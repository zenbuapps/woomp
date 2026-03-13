# Refine Data Provider References

自訂 Data Provider 實作範例。

---

## 自訂 REST Data Provider 完整實作

```typescript
// providers/custom-data-provider.ts
import { DataProvider, CrudFilters, CrudSorters } from "@refinedev/core";
import axios, { AxiosInstance } from "axios";

const buildUrl = (resource: string, id?: string | number) =>
  id !== undefined ? `/${resource}/${id}` : `/${resource}`;

const buildQueryParams = (
  pagination?: { current?: number; pageSize?: number; mode?: string },
  sorters?: CrudSorters,
  filters?: CrudFilters
) => {
  const params: Record<string, unknown> = {};

  if (pagination?.mode !== "off") {
    params._page = pagination?.current ?? 1;
    params._limit = pagination?.pageSize ?? 10;
  }

  if (sorters?.length) {
    params._sort = sorters[0].field;
    params._order = sorters[0].order;
  }

  if (filters?.length) {
    for (const filter of filters) {
      if ("field" in filter) {
        const { field, operator, value } = filter;
        switch (operator) {
          case "eq":    params[field] = value; break;
          case "ne":    params[`${field}_ne`] = value; break;
          case "lt":    params[`${field}_lt`] = value; break;
          case "gt":    params[`${field}_gt`] = value; break;
          case "lte":   params[`${field}_lte`] = value; break;
          case "gte":   params[`${field}_gte`] = value; break;
          case "contains": params[`${field}_like`] = value; break;
          case "in":    params[`${field}_in`] = value.join(","); break;
        }
      }
    }
  }

  return params;
};

export const createCustomDataProvider = (
  apiUrl: string,
  httpClient: AxiosInstance = axios
): DataProvider => ({
  getList: async ({ resource, pagination, sorters, filters, meta }) => {
    const params = buildQueryParams(pagination, sorters, filters);

    const { data, headers } = await httpClient.get(buildUrl(resource), {
      params,
      headers: meta?.headers,
    });

    const total = Number(headers["x-total-count"] ?? data.meta?.total ?? data.length);

    return { data: data.items ?? data, total };
  },

  getOne: async ({ resource, id, meta }) => {
    const { data } = await httpClient.get(buildUrl(resource, id), {
      headers: meta?.headers,
    });
    return { data };
  },

  create: async ({ resource, variables, meta }) => {
    const { data } = await httpClient.post(buildUrl(resource), variables, {
      headers: meta?.headers,
    });
    return { data };
  },

  update: async ({ resource, id, variables, meta }) => {
    const { data } = await httpClient.patch(buildUrl(resource, id), variables, {
      headers: meta?.headers,
    });
    return { data };
  },

  deleteOne: async ({ resource, id, variables, meta }) => {
    const { data } = await httpClient.delete(buildUrl(resource, id), {
      data: variables,
      headers: meta?.headers,
    });
    return { data: data ?? { id } };
  },

  getApiUrl: () => apiUrl,

  // 選用：批次取得
  getMany: async ({ resource, ids }) => {
    const responses = await Promise.all(
      ids.map((id) => httpClient.get(buildUrl(resource, id)))
    );
    return { data: responses.map((r) => r.data) };
  },

  // 選用：自訂 HTTP 呼叫
  custom: async ({ url, method, payload, headers, meta }) => {
    const { data } = await httpClient.request({
      url,
      method,
      data: payload,
      headers: { ...headers, ...meta?.headers },
    });
    return { data };
  },
});
```

---

## GraphQL Data Provider 範例

```typescript
// providers/graphql-data-provider.ts
import { DataProvider } from "@refinedev/core";
import { GraphQLClient } from "graphql-request";

export const createGraphQLDataProvider = (
  endpoint: string,
  token?: string
): DataProvider => {
  const client = new GraphQLClient(endpoint, {
    headers: token ? { Authorization: `Bearer ${token}` } : {},
  });

  return {
    getList: async ({ resource, pagination, sorters, filters, meta }) => {
      const query = meta?.gqlQuery ?? `
        query Get${capitalize(resource)}($page: Int, $limit: Int) {
          ${resource}(page: $page, limit: $limit) {
            nodes { id name }
            totalCount
          }
        }
      `;

      const data = await client.request(query, {
        page: pagination?.current,
        limit: pagination?.pageSize,
      });

      const resourceData = data[resource];
      return {
        data: resourceData.nodes,
        total: resourceData.totalCount,
      };
    },

    getOne: async ({ resource, id, meta }) => {
      const query = meta?.gqlQuery ?? `
        query Get${capitalize(resource)}($id: ID!) {
          ${resource.slice(0, -1)}(id: $id) { id name }
        }
      `;
      const data = await client.request(query, { id });
      return { data: data[resource.slice(0, -1)] };
    },

    create: async ({ resource, variables, meta }) => {
      const mutation = meta?.gqlMutation ?? `
        mutation Create${capitalize(resource.slice(0, -1))}($input: Create${capitalize(resource.slice(0, -1))}Input!) {
          create${capitalize(resource.slice(0, -1))}(input: $input) { id }
        }
      `;
      const data = await client.request(mutation, { input: variables });
      return { data: data[`create${capitalize(resource.slice(0, -1))}`] };
    },

    update: async ({ resource, id, variables, meta }) => {
      const mutation = meta?.gqlMutation ?? `
        mutation Update${capitalize(resource.slice(0, -1))}($id: ID!, $input: Update${capitalize(resource.slice(0, -1))}Input!) {
          update${capitalize(resource.slice(0, -1))}(id: $id, input: $input) { id }
        }
      `;
      const data = await client.request(mutation, { id, input: variables });
      return { data: data[`update${capitalize(resource.slice(0, -1))}`] };
    },

    deleteOne: async ({ resource, id }) => {
      const mutation = `
        mutation Delete${capitalize(resource.slice(0, -1))}($id: ID!) {
          delete${capitalize(resource.slice(0, -1))}(id: $id) { id }
        }
      `;
      const data = await client.request(mutation, { id });
      return { data: data[`delete${capitalize(resource.slice(0, -1))}`] };
    },

    getApiUrl: () => endpoint,
  };
};

const capitalize = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);
```

---

## 加入 Token 的 Axios Instance

```typescript
// utils/axios.ts
import axios from "axios";

export const axiosInstance = axios.create({ baseURL: "https://api.example.com/v1" });

// 請求攔截器：自動附加 Token
axiosInstance.interceptors.request.use((config) => {
  const token = localStorage.getItem("auth_token");
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

// 回應攔截器：統一錯誤處理
axiosInstance.interceptors.response.use(
  (response) => response,
  (error) => {
    const customError = {
      message: error.response?.data?.message ?? error.message,
      name: error.response?.data?.error ?? "API Error",
      statusCode: error.response?.status,
    };
    return Promise.reject(customError);
  }
);
```
