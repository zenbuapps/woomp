# Refine Ant Design CRUD References

完整 Ant Design CRUD 頁面範例。

---

## List 頁面（搜尋 + 篩選 + 排序）

```tsx
// pages/products/list.tsx
import {
  List, useTable, EditButton, ShowButton, DeleteButton,
  FilterDropdown, getDefaultSortOrder, getDefaultFilter, DateField, TagField,
} from "@refinedev/antd";
import { Table, Space, Input, Select, Form, Button, Row, Col, Card } from "antd";
import { SearchOutlined } from "@ant-design/icons";
import { CrudFilters } from "@refinedev/core";

interface IProduct {
  id: number;
  name: string;
  price: number;
  status: "active" | "draft" | "archived";
  category: { id: number; name: string };
  createdAt: string;
}

interface IProductFilter {
  name: string;
  status: string[];
}

export const ProductList = () => {
  const { tableProps, sorters, filters, searchFormProps } = useTable<IProduct, HttpError, IProductFilter>({
    syncWithLocation: true,
    sorters: { initial: [{ field: "id", order: "desc" }] },
    onSearch: (values): CrudFilters => [
      { field: "name", operator: "contains", value: values.name },
      { field: "status", operator: "in", value: values.status },
    ],
  });

  return (
    <List>
      {/* 搜尋表單 */}
      <Card style={{ marginBottom: 16 }}>
        <Form {...searchFormProps} layout="inline">
          <Row gutter={16} style={{ width: "100%" }}>
            <Col>
              <Form.Item name="name">
                <Input placeholder="搜尋名稱" prefix={<SearchOutlined />} />
              </Form.Item>
            </Col>
            <Col>
              <Form.Item name="status">
                <Select
                  mode="multiple"
                  placeholder="篩選狀態"
                  style={{ minWidth: 160 }}
                  options={[
                    { label: "啟用", value: "active" },
                    { label: "草稿", value: "draft" },
                    { label: "封存", value: "archived" },
                  ]}
                />
              </Form.Item>
            </Col>
            <Col>
              <Button htmlType="submit" type="primary">搜尋</Button>
            </Col>
          </Row>
        </Form>
      </Card>

      {/* 資料表格 */}
      <Table {...tableProps} rowKey="id">
        <Table.Column
          dataIndex="id"
          title="ID"
          sorter
          defaultSortOrder={getDefaultSortOrder("id", sorters)}
          width={80}
        />
        <Table.Column
          dataIndex="name"
          title="產品名稱"
          sorter
          defaultSortOrder={getDefaultSortOrder("name", sorters)}
          filterDropdown={(props) => (
            <FilterDropdown {...props}>
              <Input placeholder="搜尋產品名稱" />
            </FilterDropdown>
          )}
          defaultFilteredValue={getDefaultFilter("name", filters, "contains")}
        />
        <Table.Column
          dataIndex="status"
          title="狀態"
          defaultFilteredValue={getDefaultFilter("status", filters, "in")}
          filterDropdown={(props) => (
            <FilterDropdown {...props}>
              <Select
                mode="multiple"
                style={{ minWidth: 200 }}
                options={[
                  { label: "啟用", value: "active" },
                  { label: "草稿", value: "draft" },
                  { label: "封存", value: "archived" },
                ]}
              />
            </FilterDropdown>
          )}
          render={(value) => <TagField value={value} />}
        />
        <Table.Column
          dataIndex="price"
          title="價格"
          sorter
          render={(value) => `$${value?.toLocaleString()}`}
        />
        <Table.Column
          dataIndex="createdAt"
          title="建立日期"
          sorter
          render={(value) => <DateField value={value} format="YYYY/MM/DD" />}
        />
        <Table.Column
          title="操作"
          render={(_, record: IProduct) => (
            <Space>
              <ShowButton recordItemId={record.id} hideText size="small" />
              <EditButton recordItemId={record.id} hideText size="small" />
              <DeleteButton recordItemId={record.id} hideText size="small" />
            </Space>
          )}
        />
      </Table>
    </List>
  );
};
```

---

## Show 頁面

```tsx
// pages/products/show.tsx
import { Show, TextField, NumberField, DateField, TagField, MarkdownField } from "@refinedev/antd";
import { useShow } from "@refinedev/core";
import { Typography, Row, Col, Descriptions } from "antd";

const { Title } = Typography;

export const ProductShow = () => {
  const { query: { data, isLoading } } = useShow<IProduct>();
  const record = data?.data;

  return (
    <Show isLoading={isLoading}>
      <Descriptions bordered column={2}>
        <Descriptions.Item label="ID">
          <TextField value={record?.id} />
        </Descriptions.Item>
        <Descriptions.Item label="產品名稱">
          <TextField value={record?.name} />
        </Descriptions.Item>
        <Descriptions.Item label="價格">
          <NumberField
            value={record?.price}
            options={{ style: "currency", currency: "TWD" }}
          />
        </Descriptions.Item>
        <Descriptions.Item label="狀態">
          <TagField value={record?.status} />
        </Descriptions.Item>
        <Descriptions.Item label="建立日期" span={2}>
          <DateField value={record?.createdAt} format="YYYY/MM/DD HH:mm:ss" />
        </Descriptions.Item>
        <Descriptions.Item label="描述" span={2}>
          <MarkdownField value={record?.description} />
        </Descriptions.Item>
      </Descriptions>
    </Show>
  );
};
```

---

## Edit 頁面

```tsx
// pages/products/edit.tsx
import { Edit, useForm, useSelect } from "@refinedev/antd";
import { Form, Input, InputNumber, Select, Upload } from "antd";

export const ProductEdit = () => {
  const { formProps, saveButtonProps, query } = useForm<IProduct>({
    redirect: "show",
    warnWhenUnsavedChanges: true,
    onMutationSuccess: () => {
      console.log("更新成功");
    },
    onMutationError: (error) => {
      console.error("更新失敗:", error);
    },
  });

  // 下拉選單資料（透過 useSelect 從 API 載入）
  const { selectProps: categorySelectProps } = useSelect({
    resource: "categories",
    optionLabel: "name",
    optionValue: "id",
    defaultValue: query?.data?.data?.categoryId,
  });

  return (
    <Edit saveButtonProps={saveButtonProps}>
      <Form {...formProps} layout="vertical">
        <Form.Item label="產品名稱" name="name" rules={[{ required: true }]}>
          <Input />
        </Form.Item>
        <Form.Item label="價格" name="price" rules={[{ required: true }]}>
          <InputNumber min={0} style={{ width: "100%" }} />
        </Form.Item>
        <Form.Item label="分類" name="categoryId">
          <Select {...categorySelectProps} />
        </Form.Item>
        <Form.Item label="狀態" name="status">
          <Select
            options={[
              { label: "啟用", value: "active" },
              { label: "草稿", value: "draft" },
              { label: "封存", value: "archived" },
            ]}
          />
        </Form.Item>
        <Form.Item label="描述" name="description">
          <Input.TextArea rows={6} />
        </Form.Item>
      </Form>
    </Edit>
  );
};
```

---

## Create 頁面

```tsx
// pages/products/create.tsx
import { Create, useForm, useSelect } from "@refinedev/antd";
import { Form, Input, InputNumber, Select } from "antd";

export const ProductCreate = () => {
  const { formProps, saveButtonProps } = useForm<IProduct>({
    redirect: "list",
  });

  const { selectProps: categorySelectProps } = useSelect({
    resource: "categories",
    optionLabel: "name",
    optionValue: "id",
  });

  return (
    <Create saveButtonProps={saveButtonProps}>
      <Form {...formProps} layout="vertical">
        <Form.Item label="產品名稱" name="name" rules={[{ required: true }]}>
          <Input />
        </Form.Item>
        <Form.Item label="價格" name="price" rules={[{ required: true }]}>
          <InputNumber min={0} style={{ width: "100%" }} />
        </Form.Item>
        <Form.Item label="分類" name="categoryId" rules={[{ required: true }]}>
          <Select {...categorySelectProps} />
        </Form.Item>
        <Form.Item label="狀態" name="status" initialValue="draft">
          <Select
            options={[
              { label: "啟用", value: "active" },
              { label: "草稿", value: "draft" },
            ]}
          />
        </Form.Item>
      </Form>
    </Create>
  );
};
```

---

## 帶有巢狀表單的進階 Create

```tsx
export const OrderCreate = () => {
  const { formProps, saveButtonProps } = useForm({ redirect: "list" });
  const [form] = Form.useForm();

  return (
    <Create saveButtonProps={saveButtonProps}>
      <Form {...formProps} form={form} layout="vertical">
        <Form.Item label="客戶" name="customerId" rules={[{ required: true }]}>
          <Input />
        </Form.Item>

        {/* 動態陣列欄位 */}
        <Form.List name="items">
          {(fields, { add, remove }) => (
            <>
              {fields.map(({ key, name }) => (
                <Row key={key} gutter={16}>
                  <Col flex="auto">
                    <Form.Item name={[name, "productId"]} label="產品">
                      <Input />
                    </Form.Item>
                  </Col>
                  <Col flex="80px">
                    <Form.Item name={[name, "quantity"]} label="數量">
                      <InputNumber min={1} />
                    </Form.Item>
                  </Col>
                  <Col flex="40px">
                    <Button danger onClick={() => remove(name)}>刪除</Button>
                  </Col>
                </Row>
              ))}
              <Button onClick={() => add()}>新增項目</Button>
            </>
          )}
        </Form.List>
      </Form>
    </Create>
  );
};
```

---

## AuthPage 設定

```tsx
import { AuthPage } from "@refinedev/antd";

// 登入頁
<Route path="/login" element={
  <AuthPage
    type="login"
    title="管理後台"
    renderContent={(content) => (
      <div>
        {content}
        <Divider />
        <a href="/register">還沒有帳號？立即註冊</a>
      </div>
    )}
  />
} />

// 其他頁面
<Route path="/register" element={<AuthPage type="register" />} />
<Route path="/forgot-password" element={<AuthPage type="forgotPassword" />} />
<Route path="/reset-password" element={<AuthPage type="resetPassword" />} />
```
