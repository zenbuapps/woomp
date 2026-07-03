# PAYUNi UNi Embed V3 — Next.js 16 / React 19 前端整合

> 配合本專案 `apps/web/`（Next.js 16 + React 19 + Refine v5 + react-hook-form + Zod）。
> 主要變動點：iframe SDK 必須在 client component 載入；CSP 需放行 vendor.payuni.com.tw；流程兩段 + 一個原子 SDK_TOKEN。

## TOC

- [next.config.mjs CSP 設定](#nextconfigmjs-csp-設定)
- [Checkout 元件（client component）](#checkout-元件client-component)
- [TypeScript 型別](#typescript-型別)
- [使用 Refine useCustomMutation 呼叫後端](#使用-refine-usecustommutation-呼叫後端)
- [完整流程除錯清單](#完整流程除錯清單)

---

## next.config.mjs CSP 設定

```js
// apps/web/next.config.mjs
/** @type {import('next').NextConfig} */
const nextConfig = {
  async headers() {
    const isProd = process.env.NODE_ENV === 'production';
    const vendorOrigin = isProd
      ? 'https://vendor.payuni.com.tw'
      : 'https://vendor.payuni.com.tw https://sandbox-vendor.payuni.com.tw';
    return [
      {
        source: '/:path*',
        headers: [
          {
            key: 'Content-Security-Policy',
            value: [
              `default-src 'self'`,
              `script-src 'self' 'unsafe-inline' 'unsafe-eval' ${vendorOrigin}`,
              `frame-src ${vendorOrigin}`,
              `connect-src 'self' https://api.payuni.com.tw https://sandbox-api.payuni.com.tw`,
              `style-src 'self' 'unsafe-inline'`,
            ].join('; '),
          },
        ],
      },
    ];
  },
};
export default nextConfig;
```

> **正式環境** 不要保留 sandbox-vendor 的放行；測試與正式分開設定。

---

## Checkout 元件（client component）

```tsx
// apps/web/components/shop/PayuniUniEmbedCheckout.tsx
'use client';

import { useEffect, useRef, useState } from 'react';
import Script from 'next/script';
import type { UniPaymentSDK, UniPaymentStatus, UniPaymentEvent } from './payuni-uni-types';

declare global {
  interface Window {
    UniPayment: {
      createSession(token: string, options: UniPaymentInitOptions): UniPaymentSDK;
    };
  }
}

interface Props {
  /** 從後端 token_get 取得的 SDK_TOKEN */
  sdkToken: string;
  /** Token 過期 unix timestamp（用來判斷重新請求） */
  sdkTokenExpiredAt: number;
  /** 環境：sandbox 顯示測試提示 */
  env: 'P' | 'S';
  /** 商店訂單編號（merchant_trade 階段送） */
  merTradeNo: string;
  /** 確認金額（後端購物車金額） */
  tradeAmt: number;
  /** 是否啟用記憶卡號 / 約定信用卡 */
  useTokenType?: 1 | 2 | 3;
  /** 是否啟用分期 */
  useInst?: boolean;
  /** 後端 authorize endpoint */
  authorizeEndpoint: string;
  onSuccess?: (result: AuthorizeResult) => void;
  onFailure?: (error: { code: string; message: string }) => void;
}

interface UniPaymentInitOptions {
  env: 'P' | 'S';
  useInst: boolean;
  elements: {
    CardNo: string;
    CardExp: string;
    CardCvc: string;
    CardTokenType?: string;
  };
  style?: {
    color?: string;
    errorColor?: string;
    fontSize?: string;
    fontWeight?: string;
    lineHeight?: string;
  };
}

interface AuthorizeResult {
  Status: string;
  TradeStatus?: string;
  TradeNo?: string;
  URL?: string; // API3D=1 時的 3D 導頁
  // ... 詳見 SKILL.md
}

export function PayuniUniEmbedCheckout(props: Props) {
  const sdkRef = useRef<UniPaymentSDK | null>(null);
  const [sdkLoaded, setSdkLoaded] = useState(false);
  const [fieldStatus, setFieldStatus] = useState<{
    CardNo: UniPaymentStatus;
    CardExp: UniPaymentStatus;
    CardCvc: UniPaymentStatus;
  }>({ CardNo: null, CardExp: null, CardCvc: null });
  const [tokenTypeData, setTokenTypeData] = useState<{
    tokenType: '1' | '2' | '3';
    tokenTypeText: string;
    cardNo: string | null;
  } | null>(null);
  const [instOptions, setInstOptions] = useState<Record<string, string>>({});
  const [selectedInst, setSelectedInst] = useState<number>(1);
  const [useDefaultMemo, setUseDefaultMemo] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  /** SDK 載入後，建立 session 並 start */
  useEffect(() => {
    if (!sdkLoaded || sdkRef.current) return;

    const sdk = window.UniPayment.createSession(props.sdkToken, {
      env: props.env,
      useInst: !!props.useInst,
      elements: {
        CardNo: 'put_card_no',
        CardExp: 'put_card_exp',
        CardCvc: 'put_card_cvc',
        CardTokenType: props.useTokenType ? 'put_token_type' : undefined,
      },
      style: {
        color: '#1f2937',
        errorColor: '#ef4444',
        fontSize: '14px',
        fontWeight: '400',
        lineHeight: '24px',
      },
    });

    sdk.onUpdate((update) => {
      if (update.status) setFieldStatus(update.status);
      if (update.event === 'useTokenType' && update.data) {
        setTokenTypeData(update.data);
        const area = document.getElementById('token_type_checkbox_area');
        if (area) area.style.display = 'flex';
      }
    });

    sdk.start()
      .then(async () => {
        sdkRef.current = sdk;
        if (props.useInst) {
          const info = await sdk.getCardAcceptInfo();
          setInstOptions(info.CreditInst ?? {});
        }
      })
      .catch((err) => {
        const code = err.message?.match(/Code (\d+)/)?.[1];
        if (code === '1008') {
          props.onFailure?.({ code: '1008', message: 'iframe 連線超時，請重新整理' });
        } else {
          props.onFailure?.({ code: code ?? 'UNKNOWN', message: err.message });
        }
      });
  }, [sdkLoaded, props]);

  const allValid = fieldStatus.CardNo === true
    && fieldStatus.CardExp === true
    && fieldStatus.CardCvc === true;

  /**
   * V3 三段流程：getTradeResult（SDK 綁定，不授權）→ 後端 merchant_trade（真正的授權）→ 處理結果
   *
   * 重要：這裡的 getTradeResult 在 V3 只負責「SDK Token 綁定」，不執行交易授權。
   * 官方版本差異頁原文：「SDK 僅負責蒐集信用卡資訊並進行 SDK Token 綁定。
   * 商戶前端取得綁定結果後，需自行呼叫另一支 API 進行交易授權」。
   * 若你看 V3 主文件 API 表格寫「進行交易並取得加密的交易結果」——
   * 那是沿用 V2 措辭，V3 實際語意是「綁定」。
   */
  async function handlePay() {
    if (!sdkRef.current) return;
    setSubmitting(true);
    try {
      // Step 1：SDK 取得綁定結果（V3 只綁定，不授權；不含訂單金額）
      await sdkRef.current.getTradeResult({
        cardInst: selectedInst,
        useDefault: useDefaultMemo,
      });

      // Step 2：後端用同一個 SDK_TOKEN + 訂單資料呼叫 merchant_trade，完成幕後授權
      const resp = await fetch(props.authorizeEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({
          sdkToken: props.sdkToken,
          merTradeNo: props.merTradeNo,
          tradeAmt: props.tradeAmt,
          prodDesc: '訂單付款', // 由後端控制
        }),
      });
      const result: AuthorizeResult = await resp.json();

      // Step 3：依 result 處理
      if (result.URL) {
        // API3D=1 時，跳轉至銀行 3D 驗證頁
        window.location.href = result.URL;
        return;
      }
      if (result.Status === 'SUCCESS' && result.TradeStatus === '1') {
        props.onSuccess?.(result);
      } else {
        props.onFailure?.({ code: result.Status, message: 'Payment failed' });
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Unknown error';
      props.onFailure?.({ code: 'CLIENT_ERROR', message: msg });
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      <Script
        src="https://vendor.payuni.com.tw/sdk/uni-payment.js"
        strategy="afterInteractive"
        onLoad={() => setSdkLoaded(true)}
      />
      <div className="space-y-4">
        <div>
          <label className="block text-sm font-medium text-gray-700">信用卡號碼</label>
          <div id="put_card_no" className="mt-1 h-10 border border-gray-300 rounded px-3" />
        </div>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700">有效期限 (MMYY)</label>
            <div id="put_card_exp" className="mt-1 h-10 border border-gray-300 rounded px-3" />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">安全碼</label>
            <div id="put_card_cvc" className="mt-1 h-10 border border-gray-300 rounded px-3" />
          </div>
        </div>

        {/* 約定 / 記憶卡號 checkbox area，由 SDK 控制 display */}
        {props.useTokenType && (
          <div id="token_type_checkbox_area" style={{ display: 'none', alignItems: 'center' }}>
            <div id="put_token_type" />
            <label id="token_type_text" htmlFor="type-checkbox" className="ml-2" />
          </div>
        )}

        {/* 記憶卡號（第二次以上） */}
        {tokenTypeData?.tokenType === '2' && tokenTypeData.cardNo && (
          <div className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={useDefaultMemo}
              onChange={(e) => setUseDefaultMemo(e.target.checked)}
            />
            <span>使用記憶卡號 {tokenTypeData.cardNo} 快速結帳</span>
          </div>
        )}

        {/* 分期下拉 */}
        {props.useInst && Object.keys(instOptions).length > 0 && (
          <div>
            <label className="block text-sm font-medium text-gray-700">分期</label>
            <select
              value={selectedInst}
              onChange={(e) => setSelectedInst(Number(e.target.value))}
              className="mt-1 block w-full border border-gray-300 rounded px-3 h-10"
            >
              <option value={1}>一次付清</option>
              {Object.entries(instOptions).map(([n, banks]) => (
                <option key={n} value={n}>{n} 期（{banks}）</option>
              ))}
            </select>
          </div>
        )}

        <button
          type="button"
          onClick={handlePay}
          disabled={!allValid || submitting || !sdkRef.current}
          className="w-full h-11 bg-blue-600 text-white rounded disabled:bg-gray-300"
        >
          {submitting ? '處理中…' : `付款 NT$ ${props.tradeAmt.toLocaleString()}`}
        </button>
      </div>

      <style jsx global>{`
        .form-input-focus {
          outline: 0;
          box-shadow: 0 0 4px 0.1rem #0485ff73;
        }
      `}</style>
    </>
  );
}
```

---

## TypeScript 型別

```ts
// apps/web/components/shop/payuni-uni-types.ts

export type UniPaymentStatus = true | false | null | 'typing';

export type UniPaymentEvent = 'useTokenType';

export interface UniPaymentUpdate {
  status?: {
    CardNo: UniPaymentStatus;
    CardExp: UniPaymentStatus;
    CardCvc: UniPaymentStatus;
  };
  event?: UniPaymentEvent;
  data?: {
    tokenType: '1' | '2' | '3';
    tokenTypeText: string;
    cardNo: string | null;
  };
}

export interface UniPaymentSDK {
  start(): Promise<unknown>;
  onUpdate(callback: (update: UniPaymentUpdate) => void): void;
  getCardAcceptInfo(): Promise<{ CreditInst: Record<string, string> }>;
  getTokenTypeText(callback: (text: string) => void): string;
  getTradeResult(config?: {
    cardInst?: number;
    useDefault?: boolean;
  }): Promise<{
    EncryptInfo: string;
    HashInfo: string;
    MerID: string;
    Status: string;
    Version: string;
  }>;
}
```

---

## 使用 Refine useCustomMutation 呼叫後端

```tsx
import { useCustomMutation } from '@refinedev/core';

function CheckoutPage({ orderId }: { orderId: string }) {
  // 1) 結帳頁載入時取得 SDK_TOKEN
  const { mutate: getToken, data: tokenData } = useCustomMutation({
    mutationOptions: {
      mutationKey: ['payuni-uni-token'],
    },
  });

  useEffect(() => {
    getToken({
      url: '/v1/checkout/payuni-uni/token',
      method: 'post',
      values: {
        iframeDomain: window.location.origin,
        // 可選：useTokenType + creditToken（會員）
      },
    });
  }, []);

  if (!tokenData?.data?.sdkToken) return <div>載入中…</div>;

  return (
    <PayuniUniEmbedCheckout
      sdkToken={tokenData.data.sdkToken}
      sdkTokenExpiredAt={tokenData.data.expiredAt}
      env={process.env.NEXT_PUBLIC_PAYUNI_ENV === 'production' ? 'P' : 'S'}
      merTradeNo={`ORD-${orderId}`}
      tradeAmt={1280}
      useInst
      authorizeEndpoint="/v1/checkout/payuni-uni/authorize"
      onSuccess={(r) => router.push(`/shop/order/${orderId}/success`)}
      onFailure={(e) => toast.error(`付款失敗 ${e.code}: ${e.message}`)}
    />
  );
}
```

> **不要直接 fetch**：若場景需 Refine `useCustomMutation` 不夠用（如 Form POST），仍建議透過 `lib/api.ts` 集中封裝（見 `.claude/rules/nextjs-frontend.rule.md`）。

---

## 完整流程除錯清單

當前端 SDK 出錯時依以下步驟排查：

### 1. SDK 沒載入

```
Uncaught ReferenceError: UniPayment is not defined
```

- 檢查 `<Script src="https://vendor.payuni.com.tw/sdk/uni-payment.js" />` 是否成功載入
- 檢查 CSP 是否包含 `script-src https://vendor.payuni.com.tw`
- DevTools Network 面板是否看到 `uni-payment.js` 200 OK

### 2. iframe 顯示不出來（`Code 1001`）

- 檢查 `elements.CardNo / CardExp / CardCvc` 對應的 `<div id>` 是否存在於 DOM
- 檢查 `frame-src` CSP 是否放行
- 等待 `start()` Promise resolve 才呼叫其他方法

### 3. 來源驗證失敗（`Code 1007`）

- token_get 階段傳入的 `IFrameDomain` 必須與當前頁面 `window.location.origin` 完全吻合
- 含 `https://`、不含 path、不含 query
- 範例：`https://shop.example.com`（**正確**）vs `https://shop.example.com/`（**錯誤，多了斜線**）

### 4. 連線超時（`Code 1008` / `Code 1009`）

- 通常為網路問題或 PAYUNi 端故障
- UX：顯示「請重新整理」按鈕，重新呼叫 `token_get`

### 5. SDK Token 失效（`OBJ01003` / `IFTRADE04001`）

- token_get 取得後 10 分鐘內必須完成 `merchant_trade`
- 重新呼叫 `token_get` 再走一次流程

### 6. CSP 阻擋

DevTools Console 出現：
```
Refused to load the script 'https://vendor.payuni.com.tw/sdk/uni-payment.js' because it violates the following Content Security Policy directive: ...
```

→ 修正 `next.config.mjs` 的 CSP 設定，重啟 dev server。

### 7. Origin 取不到（Safari 私密瀏覽）

SDK 不會中斷流程，但會在卡號框下方顯示警語。可在 UI 加入提示「Safari 私密瀏覽可能影響交易安全，建議改用一般視窗」。
