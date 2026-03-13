# Refine REST Data Provider (@refinedev/rest) References

`@refinedev/rest` 的完整設定與自訂範例。

---

## 基本設定

```bash
npm i @refinedev/rest
```

```typescript
// App.tsx
import { createDataProvider } from "@refinedev/rest";

const { dataProvider, kyInstance } = createDataProvider("https://api.example.com/v1");

// kyInstance 可用於 Auth Provider 的 HTTP 呼叫
```

---

## 完整自訂設定

```typescript
import { createDataProvider } from "@refinedev/rest";

const { dataProvider, kyInstance } = createDataProvider(
  "https://api.example.com/v1",

  // 方法級別的自訂選項
  {
    // ─── getList ─────────────────────────────────────────────
    getList: {
      // 自訂 endpoint（預設為 /:resource）
      getEndpoint: ({ resource, pagination, sorters, filters, meta }) =>
        `/${resource}?format=json`,

      // 自訂 query string 建構
      buildQueryParams: ({ resource, pagination, sorters, filters, meta }) => {
        const params: Record<string, unknown> = {
          page: pagination?.current ?? 1,
          per_page: pagination?.pageSize ?? 20,
        };

        if (sorters?.length) {
          params.sort_by = sorters[0].field;
          params.sort_dir = sorters[0].order;
        }

        if (filters?.length) {
          for (const filter of filters) {
            if ("field" in filter) {
              params[`filter[${filter.field}]`] = filter.value;
            }
          }
        }

        return params;
      },

      // 自訂 headers
      buildHeaders: ({ meta }) => ({
        "X-Custom-Header": "refine",
        ...(meta?.headers ?? {}),
      }),

      // 自訂如何解析 total 數量
      getTotalCount: ({ response, data }) => {
        // data 為已解析的 JSON，response 為原始 Response
        return data?.meta?.total ?? Number(response.headers.get("x-total-count")) ?? 0;
      },

      // 自訂如何從 response body 提取 data array
      mapResponse: ({ data }) => {
        return data?.items ?? data?.data ?? data;
      },
    },

    // ─── getOne ──────────────────────────────────────────────
    getOne: {
      mapResponse: ({ data }) => data?.item ?? data,
    },

    // ─── create ──────────────────────────────────────────────
    create: {
      buildBodyParams: ({ variables, meta }) => ({
        data: variables,
        ...(meta?.extraFields ?? {}),
      }),
      mapResponse: ({ data }) => data?.item ?? data,
    },

    // ─── update ──────────────────────────────────────────────
    update: {
      buildBodyParams: ({ variables }) => ({ data: variables }),
      mapResponse: ({ data }) => data?.item ?? data,
    },

    // ─── deleteOne ───────────────────────────────────────────
    deleteOne: {
      mapResponse: ({ data }) => data ?? {},
    },

    // ─── custom ──────────────────────────────────────────────
    custom: {
      buildHeaders: ({ meta }) => ({
        "X-Requested-With": "XMLHttpRequest",
      }),
    },
  },

  // KY 選項（第三參數）
  {
    hooks: {
      beforeRequest: [
        (request) => {
          const token = localStorage.getItem("auth_token");
          if (token) {
            request.headers.set("Authorization", `Bearer ${token}`);
          }
        },
      ],
      afterResponse: [
        async (request, options, response) => {
          if (response.status === 401) {
            localStorage.removeItem("auth_token");
            window.location.href = "/login";
          }
          return response;
        },
      ],
    },
    timeout: 30000,
  }
);
```

---

## 自訂 transformError

```typescript
import { createDataProvider } from "@refinedev/rest";
import { HttpError } from "@refinedev/core";

const { dataProvider } = createDataProvider("https://api.example.com/v1", {
  // 全域 transformError 需在每個方法設定，或建議用 ky hooks
  getList: {
    transformError: async ({ error }) => {
      // error 為原始 Error 物件
      if (error instanceof Error && "response" in error) {
        const response = (error as any).response as Response;
        const body = await response.json().catch(() => ({}));

        const httpError: HttpError = {
          message: body.message ?? error.message ?? "Unknown error",
          statusCode: response.status,
        };
        return httpError;
      }
      return error;
    },
  },
});
```

---

## 使用 kyInstance 的 Auth Provider

```typescript
import { createDataProvider } from "@refinedev/rest";
import { AuthProvider } from "@refinedev/core";

const BASE_URL = "https://api.example.com/v1";
const { dataProvider, kyInstance } = createDataProvider(BASE_URL);

export const authProvider: AuthProvider = {
  login: async ({ username, password }) => {
    try {
      const data = await kyInstance
        .post(`${BASE_URL}/auth/login`, { json: { username, password } })
        .json<{ token: string }>();

      localStorage.setItem("auth_token", data.token);
      return { success: true, redirectTo: "/" };
    } catch {
      return {
        success: false,
        error: { name: "LoginError", message: "帳號或密碼錯誤" },
      };
    }
  },

  logout: async () => {
    localStorage.removeItem("auth_token");
    return { success: true, redirectTo: "/login" };
  },

  check: async () => {
    const token = localStorage.getItem("auth_token");
    return token
      ? { authenticated: true }
      : { authenticated: false, redirectTo: "/login" };
  },

  getIdentity: async () => {
    const token = localStorage.getItem("auth_token");
    if (!token) return null;

    const data = await kyInstance
      .get(`${BASE_URL}/auth/me`)
      .json<{ id: number; name: string; email: string }>();

    return { id: data.id, name: data.name, email: data.email };
  },

  onError: async (error) => {
    if (error.statusCode === 401) {
      return { logout: true, redirectTo: "/login" };
    }
    return { error };
  },
};
```

---

## 多個 Data Provider

```typescript
import { createDataProvider } from "@refinedev/rest";
import { Refine } from "@refinedev/core";

const { dataProvider: mainProvider } = createDataProvider("https://api.example.com/v1");
const { dataProvider: legacyProvider } = createDataProvider("https://legacy.example.com/api");
const { dataProvider: analyticsProvider } = createDataProvider("https://analytics.example.com");

// App.tsx
<Refine
  dataProvider={{
    default: mainProvider,
    legacy: legacyProvider,
    analytics: analyticsProvider,
  }}
  resources={[
    { name: "products" },                                    // 使用 default
    { name: "old-records", meta: { dataProviderName: "legacy" } },
    { name: "reports", meta: { dataProviderName: "analytics" } },
  ]}
/>

// 在 hook 中指定 dataProvider
const { data } = useList({
  resource: "reports",
  meta: { dataProviderName: "analytics" },
});
```

---

## 透過 meta 傳遞自訂參數

```typescript
// 在 hook 呼叫時傳入 meta
const { data } = useList({
  resource: "products",
  meta: {
    headers: { "X-Tenant-ID": "tenant-abc" },  // 自訂 headers
    extraParams: { include: "category,images" }, // 自訂 query 參數
  },
});

// 在 data provider 的 buildQueryParams 中讀取
buildQueryParams: ({ meta }) => ({
  ...meta?.extraParams,
}),

// 在 buildHeaders 中讀取
buildHeaders: ({ meta }) => ({
  ...meta?.headers,
}),
```
