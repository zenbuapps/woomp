# Refine App Setup References

完整的 Refine 應用程式設定範例。

---

## 完整 App.tsx（搭配 Ant Design + React Router）

```tsx
import { Refine, Authenticated } from "@refinedev/core";
import { BrowserRouter, Route, Routes, Outlet, Navigate } from "react-router";
import routerProvider, { NavigateToResource, CatchAllNavigate } from "@refinedev/react-router";
import { App as AntdApp, ConfigProvider } from "antd";
import {
  RefineThemes,
  ThemedLayout,
  useNotificationProvider,
  AuthPage,
  ErrorComponent,
} from "@refinedev/antd";
import "@refinedev/antd/dist/reset.css";

import { createDataProvider } from "@refinedev/rest";
import { ProductList } from "./pages/products/list";
import { ProductShow } from "./pages/products/show";
import { ProductEdit } from "./pages/products/edit";
import { ProductCreate } from "./pages/products/create";
import { authProvider } from "./providers/auth-provider";

const { dataProvider } = createDataProvider("https://api.example.com/v1");

export default function App() {
  return (
    <BrowserRouter>
      <ConfigProvider theme={RefineThemes.Blue}>
        <AntdApp>
          <Refine
            routerProvider={routerProvider}
            dataProvider={dataProvider}
            authProvider={authProvider}
            notificationProvider={useNotificationProvider}
            resources={[
              {
                name: "products",
                list: "/products",
                show: "/products/:id",
                edit: "/products/:id/edit",
                create: "/products/new",
                meta: {
                  label: "產品管理",
                  canDelete: true,
                },
              },
              {
                name: "categories",
                list: "/categories",
                edit: "/categories/:id/edit",
                create: "/categories/new",
                meta: { label: "分類管理" },
              },
            ]}
            options={{
              syncWithLocation: true,
              warnWhenUnsavedChanges: true,
              mutationMode: "optimistic",
            }}
          >
            <Routes>
              {/* 公開路由 */}
              <Route
                element={
                  <Authenticated fallback={<Outlet />}>
                    <NavigateToResource resource="products" />
                  </Authenticated>
                }
              >
                <Route path="/login" element={<AuthPage type="login" />} />
                <Route path="/register" element={<AuthPage type="register" />} />
                <Route path="/forgot-password" element={<AuthPage type="forgotPassword" />} />
              </Route>

              {/* 保護路由 */}
              <Route
                element={
                  <Authenticated fallback={<CatchAllNavigate to="/login" />}>
                    <ThemedLayout>
                      <Outlet />
                    </ThemedLayout>
                  </Authenticated>
                }
              >
                <Route index element={<NavigateToResource resource="products" />} />
                <Route path="/products">
                  <Route index element={<ProductList />} />
                  <Route path=":id" element={<ProductShow />} />
                  <Route path=":id/edit" element={<ProductEdit />} />
                  <Route path="new" element={<ProductCreate />} />
                </Route>
                <Route path="*" element={<ErrorComponent />} />
              </Route>
            </Routes>
          </Refine>
        </AntdApp>
      </ConfigProvider>
    </BrowserRouter>
  );
}
```

---

## Auth Provider 實作範例

```typescript
// providers/auth-provider.ts
import { AuthProvider } from "@refinedev/core";
import axios from "axios";

export const authProvider: AuthProvider = {
  login: async ({ email, password }) => {
    try {
      const { data } = await axios.post("/api/auth/login", { email, password });
      localStorage.setItem("auth_token", data.token);
      return { success: true, redirectTo: "/" };
    } catch (error) {
      return {
        success: false,
        error: { message: "登入失敗", name: "Invalid credentials" },
      };
    }
  },

  logout: async () => {
    localStorage.removeItem("auth_token");
    return { success: true, redirectTo: "/login" };
  },

  check: async () => {
    const token = localStorage.getItem("auth_token");
    if (!token) {
      return { authenticated: false, redirectTo: "/login" };
    }
    return { authenticated: true };
  },

  onError: async (error) => {
    if (error?.statusCode === 401) {
      return { logout: true, redirectTo: "/login" };
    }
    return { error };
  },

  getIdentity: async () => {
    const token = localStorage.getItem("auth_token");
    if (!token) return null;

    const { data } = await axios.get("/api/auth/me", {
      headers: { Authorization: `Bearer ${token}` },
    });
    return { id: data.id, name: data.name, avatar: data.avatar };
  },

  getPermissions: async () => {
    const token = localStorage.getItem("auth_token");
    if (!token) return [];
    const { data } = await axios.get("/api/auth/permissions");
    return data.roles; // ["admin", "editor"]
  },
};
```

---

## 多租戶設定

```tsx
// 透過 meta 傳遞 tenant ID
<Refine
  dataProvider={{
    default: createTenantDataProvider("https://api.example.com"),
  }}
>

// createTenantDataProvider.ts
import { createDataProvider } from "@refinedev/rest";

export const createTenantDataProvider = (baseUrl: string) => {
  const { dataProvider, kyInstance } = createDataProvider(baseUrl, {
    getList: {
      buildHeaders: ({ meta }) => ({
        "x-tenant-id": meta?.tenantId ?? localStorage.getItem("tenantId"),
      }),
    },
  });
  return dataProvider;
};

// 使用時傳入 tenantId
useList({
  resource: "products",
  meta: { tenantId: "acme-corp" },
});
```

---

## Access Control Provider 設定

```typescript
import { AccessControlProvider } from "@refinedev/core";

export const accessControlProvider: AccessControlProvider = {
  can: async ({ resource, action, params }) => {
    const permissions = await fetchPermissions(); // 從 API 或 auth token 取得

    const allowed = permissions.some(
      (p) => p.resource === resource && p.actions.includes(action)
    );

    return {
      can: allowed,
      reason: allowed ? undefined : "您沒有執行此操作的權限",
    };
  },
};

// 在 App.tsx
<Refine accessControlProvider={accessControlProvider}>
```
