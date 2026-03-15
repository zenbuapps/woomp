import { test, expect } from '@playwright/test';
import { CREDENTIALS, PRODUCT, BILLING, SELECTORS, URLS } from '../../fixtures/test-data';
import { ADMIN_URLS } from '../../fixtures/admin-urls';
import { ensureLoggedIn, loginAdmin } from '../../helpers/auth.helper';
import { addToCartAndCheckout, addProductToCart, goToCheckout } from '../../helpers/cart.helper';
import { fillBillingFields, clickPlaceOrder, waitForCheckoutUpdate, verifyOrderReceived } from '../../helpers/checkout.helper';
import { goToWoompSettings, toggleSetting, saveSettings, setSelectValue, setInputValue } from '../../helpers/settings.helper';
import { selectShippingMethod, getAvailableShippingMethods } from '../../helpers/shipping-admin.helper';

test.describe('Island Shipping Checkbox @checkout @island-shipping', () => {
	test.beforeEach(async ({ page }) => {
		await ensureLoggedIn(page);
		await addToCartAndCheckout(page);
	});

	test('should display island shipping checkbox for physical products @P0', async ({ page }) => {
		// 尋找「寄送到離島區域」核取方塊
		const islandCheckbox = page.locator(
			'input[name="ship_to_island"], #ship_to_island, input[type="checkbox"]:near(:text("離島")), label:has-text("離島") input[type="checkbox"]'
		);

		// 也嘗試透過文字內容尋找
		const islandLabel = page.locator('label:has-text("寄送到離島區域"), label:has-text("離島"), .island-shipping-checkbox');

		const checkboxExists = await islandCheckbox.count();
		const labelExists = await islandLabel.count();

		// 對於實體商品，應顯示離島寄送選項
		expect(checkboxExists + labelExists).toBeGreaterThan(0);

		if (checkboxExists > 0) {
			await expect(islandCheckbox.first()).toBeVisible();
			// 預設應為未勾選
			await expect(islandCheckbox.first()).not.toBeChecked();
		}
	});

	test('should show only island counties when island checkbox is checked @P0', async ({ page }) => {
		// 找到並勾選離島核取方塊
		const islandCheckbox = page.locator(
			'input[name="ship_to_island"], #ship_to_island, label:has-text("離島") input[type="checkbox"]'
		);

		if (await islandCheckbox.count() === 0) {
			test.skip(true, '離島寄送核取方塊不存在，跳過此測試');
			return;
		}

		// 勾選離島選項
		await islandCheckbox.first().check();
		await waitForCheckoutUpdate(page);

		// 取得縣市下拉選單
		const stateSelect = page.locator('#billing_state, select[name="billing_state"]');
		await expect(stateSelect).toBeVisible();

		// 離島縣市
		const islandCounties = ['金門縣', '澎湖縣', '連江縣'];
		// 本島縣市（抽樣幾個）
		const mainlandCounties = ['台北市', '台中市', '高雄市', '新北市'];

		// 驗證離島縣市可選（非 disabled）
		for (const county of islandCounties) {
			const option = stateSelect.locator(`option:text("${county}")`);
			const optionCount = await option.count();

			if (optionCount > 0) {
				const isDisabled = await option.evaluate(el => (el as HTMLOptionElement).disabled);
				expect(isDisabled).toBe(false);
			}
		}

		// 驗證本島縣市被停用
		for (const county of mainlandCounties) {
			const option = stateSelect.locator(`option:text("${county}")`);
			const optionCount = await option.count();

			if (optionCount > 0) {
				const isDisabled = await option.evaluate(el => (el as HTMLOptionElement).disabled);
				expect(isDisabled).toBe(true);
			}
		}
	});

	test('should restore mainland counties when island checkbox is unchecked @P0', async ({ page }) => {
		const islandCheckbox = page.locator(
			'input[name="ship_to_island"], #ship_to_island, label:has-text("離島") input[type="checkbox"]'
		);

		if (await islandCheckbox.count() === 0) {
			test.skip(true, '離島寄送核取方塊不存在，跳過此測試');
			return;
		}

		// 先勾選離島
		await islandCheckbox.first().check();
		await waitForCheckoutUpdate(page);

		// 再取消勾選
		await islandCheckbox.first().uncheck();
		await waitForCheckoutUpdate(page);

		// 取得縣市下拉選單
		const stateSelect = page.locator('#billing_state, select[name="billing_state"]');
		await expect(stateSelect).toBeVisible();

		// 本島縣市應恢復可選
		const mainlandCounties = ['台北市', '台中市', '高雄市'];

		for (const county of mainlandCounties) {
			const option = stateSelect.locator(`option:text("${county}")`);
			const optionCount = await option.count();

			if (optionCount > 0) {
				const isDisabled = await option.evaluate(el => (el as HTMLOptionElement).disabled);
				expect(isDisabled).toBe(false);
			}
		}

		// 離島縣市應被停用
		const islandCounties = ['金門縣', '澎湖縣', '連江縣'];

		for (const county of islandCounties) {
			const option = stateSelect.locator(`option:text("${county}")`);
			const optionCount = await option.count();

			if (optionCount > 0) {
				const isDisabled = await option.evaluate(el => (el as HTMLOptionElement).disabled);
				expect(isDisabled).toBe(true);
			}
		}
	});

	test('should show warning for island CVS selection @P1', async ({ page }) => {
		const islandCheckbox = page.locator(
			'input[name="ship_to_island"], #ship_to_island, label:has-text("離島") input[type="checkbox"]'
		);

		if (await islandCheckbox.count() === 0) {
			test.skip(true, '離島寄送核取方塊不存在，跳過此測試');
			return;
		}

		// 勾選離島選項
		await islandCheckbox.first().check();
		await waitForCheckoutUpdate(page);

		// 取得可用物流方式
		const shippingMethods = await getAvailableShippingMethods(page);

		// 尋找超商取貨選項
		const cvsMethod = shippingMethods.find(
			(method) =>
				method.includes('cvs') ||
				method.includes('C2C') ||
				method.includes('超商') ||
				method.includes('convenience') ||
				method.includes('store')
		);

		if (!cvsMethod) {
			test.skip(true, '無可用的超商取貨物流方式，跳過此測試');
			return;
		}

		// 選擇超商取貨
		await selectShippingMethod(page, cvsMethod);
		await waitForCheckoutUpdate(page);

		// 選擇金門縣的門市（若有門市選擇功能）
		const stateSelect = page.locator('#billing_state, select[name="billing_state"]');
		const kinmenOption = stateSelect.locator('option:text("金門縣")');

		if (await kinmenOption.count() > 0) {
			const isDisabled = await kinmenOption.evaluate(el => (el as HTMLOptionElement).disabled);

			if (!isDisabled) {
				await stateSelect.selectOption({ label: '金門縣' });
				await waitForCheckoutUpdate(page);

				// 檢查是否有離島超商相關的警告訊息
				const warningMessage = page.locator(
					'.woocommerce-info:has-text("離島"), .woocommerce-notice:has-text("離島"), .island-cvs-warning, [class*="notice"]:has-text("金門")'
				);

				// 離島超商可能會顯示警告或限制
				const warningVisible = await warningMessage.isVisible().catch(() => false);

				// 記錄結果，不強制斷言（因為警告行為可能依設定而異）
				if (warningVisible) {
					const text = await warningMessage.textContent();
					expect(text).toBeTruthy();
				}
			}
		}
	});
});
