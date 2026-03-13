---
name: refine
description: Refine React meta-framework 開發專家。精通 CRUD-heavy 應用程式架構、Data/Auth/Router Provider 系統、Ant Design UI 整合、REST Data Provider (@refinedev/rest)。當使用者需要建立 Refine 應用程式、實作 Provider、整合 Ant Design 元件、配置路由或資料層時，請啟用此技能。
metadata:
  domain: refine-react-framework
  version: "1.0"
compatibility: "@refinedev/core ^4.x, @refinedev/antd ^5.x, @refinedev/react-router ^1.x"
---

# Refine — React CRUD Meta-Framework 開發指南

## 核心概念

Refine 是一個針對 **CRUD-heavy** 網頁應用程式優化的 React meta-framework，採用 **headless 架構**，商業邏輯與 UI 完全解耦。

### 三大支柱

1. **Provider 系統** — 可替換的服務層（Data、Auth、Router、Notification、i18n、Access Control）
2. **Resource 概念** — 將 API endpoint 映射至 CRUD 路由的核心抽象
3. **Hooks 架構** — 基於 TanStack Query 的 headless data hooks

---

## 安裝與基本設定

```bash
npm create refine-app@latest
# 或手動安裝
npm i @refinedev/core @refinedev/react-router @refinedev/antd antd
npm i @refinedev/rest  # REST Data Provider
```

### 最小化 App.tsx

```tsx
import { Refine } from "@refinedev/core";
import { BrowserRouter, Route, Routes } from "react-router";
import routerProvider from "@refinedev/react-router";
import dataProvider from "@refinedev/simple-rest";

export default function App() {
  return (
    <BrowserRouter>
      <Refine
        routerProvider={routerProvider}
        dataProvider={dataProvider("https://api.example.com")}
        resources={[
          {
            name: "products",
            list: "/products",
            show: "/products/:id",
            edit: "/products/:id/edit",
            create: "/products/new",
          },
        ]}
      >
        <Routes>
          <Route path="/products" element={<ProductList />} />
        </Routes>
      </Refine>
    </BrowserRouter>
  );
}
```

> 完整 App 範例見 [references/app-setup.md](./references/app-setup.md)

---

## `<Refine>` Component 屬性

| 屬性 | 類型 | 說明 |
|------|------|------|
| `dataProvider` | `DataProvider \| Record<string, DataProvider>` | 必要。單一或多個 data provider |
| `routerProvider` | `RouterProvider` | 路由整合 |
| `authProvider` | `AuthProvider` | 認證/授權 |
| `notificationProvider` | `NotificationProvider` | 通知系統 |
| `i18nProvider` | `I18nProvider` | 國際化 |
| `accessControlProvider` | `AccessControlProvider` | 存取控制 |
| `resources` | `ResourceProps[]` | 資源定義清單 |
| `options` | `RefineOptions` | 全域設定（mutationMode、syncWithLocation 等）|

### Resource 定義

```tsx
resources={[
  {
    name: "products",          // API resource 名稱（傳給 data provider）
    identifier: "my-products", // 可選，用於 UI 匹配（不影響 API 呼叫）
    list: "/products",
    show: "/products/:id",
    edit: "/products/:id/edit",
    create: "/products/new",
    meta: {
      label: "產品管理",        // 選單顯示名稱
      icon: <ShopOutlined />,
      canDelete: true,
    },
  },
]}
```

---

## Data Provider 介面

Data Provider 是 Refine 與後端通訊的橋樑，需實作以下方法：

### 必要方法

```typescript
interface DataProvider {
  getList: (params: {
    resource: string;
    pagination?: { current?: number; pageSize?: number; mode?: "off" | "client" | "server" };
    sorters?: CrudSorters;
    filters?: CrudFilters;
    meta?: MetaQuery;
  }) => Promise<{ data: BaseRecord[]; total: number }>;

  getOne: (params: {
    resource: string;
    id: BaseKey;
    meta?: MetaQuery;
  }) => Promise<{ data: BaseRecord }>;

  create: (params: {
    resource: string;
    variables: Record<string, unknown>;
    meta?: MetaQuery;
  }) => Promise<{ data: BaseRecord }>;

  update: (params: {
    resource: string;
    id: BaseKey;
    variables: Record<string, unknown>;
    meta?: MetaQuery;
  }) => Promise<{ data: BaseRecord }>;

  deleteOne: (params: {
    resource: string;
    id: BaseKey;
    variables?: Record<string, unknown>;
    meta?: MetaQuery;
  }) => Promise<{ data: BaseRecord }>;

  getApiUrl: () => string;
}
```

### 選用方法

```typescript
getMany?: (params: { resource: string; ids: BaseKey[]; meta?: MetaQuery }) => Promise<{ data: BaseRecord[] }>;
createMany?: (params: { resource: string; variables: Record<string, unknown>[]; meta?: MetaQuery }) => Promise<{ data: BaseRecord[] }>;
updateMany?: (...) => Promise<...>;
deleteMany?: (...) => Promise<...>;
custom?: (params: { url: string; method: string; payload?: unknown; headers?: object; meta?: MetaQuery }) => Promise<{ data: unknown }>;
```

> 完整 custom Data Provider 範例見 [references/data-provider.md](./references/data-provider.md)

---

## @refinedev/rest（REST Data Provider）

新版 REST Data Provider，基於 KY HTTP client。

```bash
npm i @refinedev/rest
```

```typescript
import { createDataProvider } from "@refinedev/rest";

const { dataProvider, kyInstance } = createDataProvider(
  "https://api.example.com/v1",
  {
    // 可選：自訂每個方法的行為
    getList: {
      buildQueryParams: ({ pagination, sorters, filters }) => {
        return {
          _page: pagination?.current,
          _limit: pagination?.pageSize,
          _sort: sorters?.[0]?.field,
          _order: sorters?.[0]?.order,
          ...filters?.reduce((acc, filter) => {
            if ("field" in filter) acc[filter.field] = filter.value;
            return acc;
          }, {}),
        };
      },
      getTotalCount: ({ response }) => {
        return Number(response.headers.get("x-total-count"));
      },
    },
  }
);
```

> 完整範例見 [references/rest-data-provider.md](./references/rest-data-provider.md)

---

## Auth Provider 介面

```typescript
interface AuthProvider {
  login: (params: { email?: string; username?: string; password?: string; [key: string]: unknown }) => Promise<AuthActionResponse>;
  check: (params?: unknown) => Promise<CheckResponse>;
  logout: (params?: unknown) => Promise<AuthActionResponse>;
  onError: (error: unknown) => Promise<OnErrorResponse>;

  // 選用
  register?: (params: unknown) => Promise<AuthActionResponse>;
  forgotPassword?: (params: unknown) => Promise<AuthActionResponse>;
  updatePassword?: (params: unknown) => Promise<AuthActionResponse>;
  getPermissions?: (params?: unknown) => Promise<unknown>;
  getIdentity?: (params?: unknown) => Promise<unknown>;
}

// 回傳類型
type AuthActionResponse = {
  success: boolean;
  redirectTo?: string;
  error?: { message: string; name: string };
};

type CheckResponse = {
  authenticated: boolean;
  redirectTo?: string;    // 未認證時重導向
  logout?: boolean;       // 是否登出
  error?: Error;
};
```

### 使用 Hooks

```typescript
import { useLogin, useLogout, useIsAuthenticated, useGetIdentity } from "@refinedev/core";

const { mutate: login } = useLogin();
const { mutate: logout } = useLogout();
const { data: authData } = useIsAuthenticated();
const { data: identity } = useGetIdentity<{ name: string; email: string }>();
```

---

## Data Hooks

### useList

```typescript
import { useList } from "@refinedev/core";

const { data, isLoading, isError } = useList({
  resource: "products",
  pagination: { current: 1, pageSize: 20, mode: "server" },
  sorters: [{ field: "id", order: "desc" }],
  filters: [
    { field: "category", operator: "eq", value: "electronics" },
    { field: "price", operator: "lte", value: 1000 },
  ],
  meta: { headers: { "x-custom-header": "value" } },
});

// data.data: BaseRecord[]
// data.total: number
```

### useOne

```typescript
const { data, isLoading } = useOne({ resource: "products", id: 1 });
```

### useCreate

```typescript
const { mutate, isLoading } = useCreate();

mutate({
  resource: "products",
  values: { name: "新產品", price: 100 },
});
```

### useUpdate

```typescript
const { mutate } = useUpdate();

mutate({
  resource: "products",
  id: 1,
  values: { price: 200 },
  mutationMode: "optimistic", // "pessimistic" | "optimistic" | "undoable"
});
```

### useDelete

```typescript
const { mutate: deleteProduct } = useDelete();

deleteProduct({ resource: "products", id: 1 });
```

### useTable（@refinedev/core）

```typescript
import { useTable } from "@refinedev/core";

const { tableQuery, current, setCurrent, pageSize, setPageSize,
        sorters, setSorters, filters, setFilters } = useTable({
  resource: "products",
  syncWithLocation: true, // 同步 URL query string
});
```

### Filter Operators

| operator | 說明 |
|----------|------|
| `eq` | 等於 |
| `ne` | 不等於 |
| `lt` / `gt` | 小於 / 大於 |
| `lte` / `gte` | 小於等於 / 大於等於 |
| `contains` | 包含（字串） |
| `startswith` / `endswith` | 開頭 / 結尾 |
| `in` / `nin` | 在清單內 / 不在清單內 |
| `between` | 範圍 |
| `null` / `nnull` | 為空 / 不為空 |

---

## Ant Design UI 整合（@refinedev/antd）

```bash
npm i @refinedev/antd antd
```

### 必要設定

```tsx
import "@refinedev/antd/dist/reset.css"; // 必須匯入
import { App as AntdApp, ConfigProvider } from "antd";
import { RefineThemes, useNotificationProvider } from "@refinedev/antd";

<ConfigProvider theme={RefineThemes.Blue}>
  <AntdApp>
    <Refine notificationProvider={useNotificationProvider}>
      {/* ... */}
    </Refine>
  </AntdApp>
</ConfigProvider>
```

### ThemedLayout

```tsx
import { ThemedLayout } from "@refinedev/antd";

<Route element={<ThemedLayout />}>
  <Route path="/products" element={<ProductList />} />
</Route>
```

### CRUD 頁面元件

| 元件 | 用途 |
|------|------|
| `<List>` | 列表頁容器，自動加入建立按鈕 |
| `<Show>` | 顯示頁容器，含編輯/刪除按鈕 |
| `<Edit>` | 編輯頁容器，含儲存按鈕 |
| `<Create>` | 建立頁容器，含儲存按鈕 |

### useTable（@refinedev/antd）

```tsx
import { useTable, List, getDefaultSortOrder, getDefaultFilter, FilterDropdown } from "@refinedev/antd";
import { Table } from "antd";

export const ProductList = () => {
  const { tableProps, sorters, filters } = useTable<IProduct>({
    syncWithLocation: true,
    sorters: { initial: [{ field: "id", order: "desc" }] },
    filters: { initial: [{ field: "status", operator: "eq", value: "active" }] },
    onSearch: (values: { name: string }) => [
      { field: "name", operator: "contains", value: values.name },
    ],
  });

  return (
    <List>
      <Table {...tableProps} rowKey="id">
        <Table.Column
          dataIndex="name"
          title="名稱"
          sorter
          defaultSortOrder={getDefaultSortOrder("name", sorters)}
        />
        <Table.Column
          dataIndex="status"
          title="狀態"
          defaultFilteredValue={getDefaultFilter("status", filters)}
          filterDropdown={(props) => (
            <FilterDropdown {...props}>
              <Select options={[{ label: "啟用", value: "active" }]} />
            </FilterDropdown>
          )}
        />
      </Table>
    </List>
  );
};
```

### useForm（@refinedev/antd）

```tsx
import { Edit, useForm } from "@refinedev/antd";
import { Form, Input } from "antd";

export const ProductEdit = () => {
  const { formProps, saveButtonProps } = useForm<IProduct>({
    action: "edit", // "create" | "edit" | "clone"
    redirect: "show", // 提交後跳轉
    onMutationSuccess: (data) => {
      console.log("成功:", data);
    },
  });

  return (
    <Edit saveButtonProps={saveButtonProps}>
      <Form {...formProps} layout="vertical">
        <Form.Item label="名稱" name="name" rules={[{ required: true }]}>
          <Input />
        </Form.Item>
      </Form>
    </Edit>
  );
};
```

### 欄位顯示元件

```tsx
import { TextField, NumberField, DateField, MarkdownField, BooleanField, TagField, UrlField, EmailField, ImageField } from "@refinedev/antd";

<TextField value="文字" />
<NumberField value={1234.56} options={{ style: "currency", currency: "TWD" }} />
<DateField value="2024-01-01" format="YYYY/MM/DD" />
<BooleanField value={true} />
<TagField value="published" />
```

### 操作按鈕元件

```tsx
import { ShowButton, EditButton, DeleteButton, CreateButton, ListButton, SaveButton, RefreshButton, CloneButton } from "@refinedev/antd";

// 在 Table 中使用
<Table.Column
  title="操作"
  render={(_, record) => (
    <Space>
      <ShowButton recordItemId={record.id} hideText size="small" />
      <EditButton recordItemId={record.id} hideText size="small" />
      <DeleteButton recordItemId={record.id} hideText size="small" />
    </Space>
  )}
/>
```

> 完整 CRUD 頁面範例見 [references/antd-crud.md](./references/antd-crud.md)

---

## Router Provider

```typescript
// React Router v7
import routerProvider from "@refinedev/react-router";

// Next.js
import routerProvider from "@refinedev/nextjs-router";
```

### 路由 Hooks

```typescript
import { useGo, useBack, useParsed, useNavigation } from "@refinedev/core";

const go = useGo();
go({ to: { resource: "products", action: "list" } });
go({ to: "/custom-page", type: "push" });

const { resource, action, id, params } = useParsed();

const { list, show, edit, create } = useNavigation();
list("products");
show("products", 1);
```

---

## Notification Provider

```tsx
import { useNotificationProvider } from "@refinedev/antd";

<Refine notificationProvider={useNotificationProvider}>
  {/* 會自動顯示 CRUD 操作的成功/失敗通知 */}
</Refine>
```

### 手動觸發通知

```typescript
import { useNotification } from "@refinedev/core";

const { open, close } = useNotification();

open({
  type: "success", // "success" | "error" | "progress"
  message: "操作成功",
  description: "資料已儲存",
  key: "unique-key",  // 用於 close
});
close("unique-key");
```

---

## Access Control Provider

```typescript
import { useCan, CanAccess } from "@refinedev/core";

// Hook 方式
const { data: { can } } = useCan({
  resource: "products",
  action: "delete",
  params: { id: 1 },
});

// Component 方式
<CanAccess resource="products" action="create" fallback={<span>無權限</span>}>
  <CreateButton />
</CanAccess>
```

---

## i18n Provider

```typescript
import { I18nProvider } from "@refinedev/core";
import i18n from "i18next";

const i18nProvider: I18nProvider = {
  translate: (key: string, options?: object) => i18n.t(key, options),
  changeLocale: (lang: string) => i18n.changeLanguage(lang),
  getLocale: () => i18n.language,
};

// 必要的翻譯 key 結構：
// pages.login, pages.register, pages.error
// actions.create, actions.edit, actions.delete, actions.show
// buttons.save, buttons.cancel, buttons.confirm
// notifications.createSuccess, notifications.createError
```

---

## 進階設定

### Mutation Mode

```tsx
<Refine
  options={{
    mutationMode: "optimistic", // "pessimistic" | "optimistic" | "undoable"
    syncWithLocation: true,     // URL 與 table 狀態同步
    warnWhenUnsavedChanges: true,
  }}
>
```

### 多個 Data Provider

```tsx
<Refine
  dataProvider={{
    default: restDataProvider("https://api.example.com"),
    cms: strapiDataProvider("https://cms.example.com"),
  }}
>
```

```typescript
useList({ resource: "posts", dataProviderName: "cms" });
```

### Meta 傳遞

```typescript
// 傳遞自訂參數到 data provider
useList({
  resource: "products",
  meta: {
    headers: { Authorization: `Bearer ${token}` },
    queryContext: { tenant: "acme" },
  },
});
```

---

## 最佳實踐

1. **Resource 命名** — 使用複數小寫（`products`、`blog-posts`），對應 API path
2. **Provider 抽象** — 不要在元件內直接 fetch，透過 hooks 使用 data provider
3. **Type Safety** — 為每個 resource 定義 interface，傳給 hooks 泛型
4. **Error Handling** — Auth Provider 的 `onError` 處理 401/403 自動登出
5. **Optimistic Updates** — CRUD-heavy 應用建議使用 `mutationMode: "optimistic"`
6. **URL Sync** — 在列表頁啟用 `syncWithLocation: true` 確保狀態可分享
7. **Meta 使用** — 透過 `meta` 傳遞 headers、tenant ID 等上下文資訊

---

## 套件速查

| 套件 | 用途 |
|------|------|
| `@refinedev/core` | 核心 hooks 和 providers |
| `@refinedev/react-router` | React Router v7 整合 |
| `@refinedev/nextjs-router` | Next.js 整合 |
| `@refinedev/antd` | Ant Design UI 整合 |
| `@refinedev/mui` | Material UI 整合 |
| `@refinedev/simple-rest` | 簡易 REST data provider |
| `@refinedev/rest` | 進階 REST data provider（KY-based）|
| `@refinedev/strapi-v4` | Strapi v4 data provider |
| `@refinedev/supabase` | Supabase data provider |
