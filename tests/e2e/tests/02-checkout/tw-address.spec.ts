import { test, expect } from '@playwright/test';
import { CREDENTIALS, PRODUCT, BILLING, SELECTORS, URLS } from '../../fixtures/test-data';
import { ADMIN_URLS } from '../../fixtures/admin-urls';
import { ensureLoggedIn, loginAdmin } from '../../helpers/auth.helper';
import { addToCartAndCheckout, addProductToCart, goToCheckout } from '../../helpers/cart.helper';
import { fillBillingFields, clickPlaceOrder, waitForCheckoutUpdate, verifyOrderReceived } from '../../helpers/checkout.helper';
import { goToWoompSettings, toggleSetting, saveSettings, setSelectValue, setInputValue } from '../../helpers/settings.helper';

test.describe('Taiwan Address Dropdown @checkout @tw-address', () => {
	test.beforeEach(async ({ page }) => {
		await ensureLoggedIn(page);
		await addToCartAndCheckout(page);
	});

	test('should display county dropdown when TW address enabled @P0', async ({ page }) => {
		// 確認國家選擇為台灣
		const countryField = page.locator('#billing_country, [name="billing_country"]');
		await expect(countryField).toBeVisible();

		// 驗證縣市下拉選單出現（台灣地址功能啟用時）
		const stateSelect = page.locator('#billing_state, select[name="billing_state"]');
		await expect(stateSelect).toBeVisible();

		// 驗證是 select 元素（下拉式選單而非文字輸入）
		const tagName = await stateSelect.evaluate(el => el.tagName.toLowerCase());
		expect(tagName).toBe('select');

		// 驗證有縣市選項
		const options = stateSelect.locator('option');
		const optionCount = await options.count();
		expect(optionCount).toBeGreaterThan(1);

		// 驗證包含常見縣市名稱
		const stateHtml = await stateSelect.innerHTML();
		expect(stateHtml).toContain('台北市');
	});

	test('should auto-fill postcode when district is selected @P0', async ({ page }) => {
		// 選擇縣市
		const stateSelect = page.locator('#billing_state, select[name="billing_state"]');
		await stateSelect.selectOption({ label: '台北市' });
		await waitForCheckoutUpdate(page);

		// 選擇區域（鄉鎮市區）
		const citySelect = page.locator('#billing_city, select[name="billing_city"]');
		await expect(citySelect).toBeVisible();
		await citySelect.selectOption({ index: 1 });
		await waitForCheckoutUpdate(page);

		// 驗證郵遞區號自動填入
		const postcodeField = page.locator('#billing_postcode, [name="billing_postcode"]');
		const postcodeValue = await postcodeField.inputValue();
		expect(postcodeValue).toBeTruthy();
		expect(postcodeValue.length).toBeGreaterThanOrEqual(3);
	});

	test('should disable island counties by default @P1', async ({ page }) => {
		// 取得縣市下拉選單
		const stateSelect = page.locator('#billing_state, select[name="billing_state"]');
		await expect(stateSelect).toBeVisible();

		// 檢查離島縣市（金門縣、澎湖縣、連江縣）是否預設停用
		const islandCounties = ['金門縣', '澎湖縣', '連江縣'];

		for (const county of islandCounties) {
			const option = stateSelect.locator(`option:text("${county}")`);
			const optionExists = await option.count();

			if (optionExists > 0) {
				// 若離島選項存在，應該是 disabled 狀態
				const isDisabled = await option.evaluate(el => (el as HTMLOptionElement).disabled);
				expect(isDisabled).toBe(true);
			}
			// 若選項不存在，代表已被移除，也符合「停用」的預期
		}
	});

	test('should hide TW address dropdowns when country is not TW @P1', async ({ page }) => {
		// 先確認台灣地址下拉存在
		const stateSelect = page.locator('#billing_state, select[name="billing_state"]');
		await expect(stateSelect).toBeVisible();

		// 切換國家為非台灣（例如日本）
		const countrySelect = page.locator('#billing_country, select[name="billing_country"]');

		// 使用 Select2 或原生 select 來切換國家
		const select2 = page.locator('#select2-billing_country-container, .select2-selection--single');
		const hasSelect2 = await select2.count();

		if (hasSelect2 > 0) {
			// 使用 Select2 UI 切換
			await select2.first().click();
			const searchInput = page.locator('.select2-search__field, .select2-search input');
			await searchInput.fill('Japan');
			await page.locator('.select2-results__option:has-text("Japan")').first().click();
		} else {
			// 使用原生 select 切換
			await countrySelect.selectOption('JP');
		}

		await waitForCheckoutUpdate(page);

		// 驗證台灣地址的區域下拉消失或變為文字輸入
		const cityDropdown = page.locator('select#billing_city, select[name="billing_city"]');
		const cityDropdownVisible = await cityDropdown.isVisible().catch(() => false);

		// 切換到非台灣國家後，城市欄位不應再是台灣專用的下拉選單
		// 它可能變成文字輸入或完全不同的 UI
		if (cityDropdownVisible) {
			// 如果仍然是下拉選單，選項不應包含台灣的區域名稱
			const html = await cityDropdown.innerHTML();
			expect(html).not.toContain('中正區');
			expect(html).not.toContain('大安區');
		}

		// 郵遞區號欄位應可手動輸入（非自動帶入）
		const postcodeField = page.locator('#billing_postcode, [name="billing_postcode"]');
		const isReadonly = await postcodeField.getAttribute('readonly');
		expect(isReadonly).toBeNull();
	});
});
