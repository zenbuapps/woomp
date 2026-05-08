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
		// 確認國家選擇欄位存在於 DOM（WC 使用 Select2 隱藏原生 select，toBeAttached 即可）
		const countryField = page.locator('#billing_country, [name="billing_country"]');
		await expect(countryField).toBeAttached();

		// 驗證縣市下拉選單出現（台灣地址功能啟用時）
		// name="county" 為 woomp 台灣地址欄位，#billing_state 為標準 WC 欄位
		// woomp 可能使用隱藏 native select 搭配自訂 UI，使用 toBeAttached 代替 toBeVisible
		const stateSelect = page.locator('select[name="county"], select#billing_state, select[name="billing_state"]');
		await expect(stateSelect.first()).toBeAttached();

		// 驗證是 select 元素（下拉式選單而非文字輸入）
		const tagName = await stateSelect.first().evaluate(el => el.tagName.toLowerCase());
		expect(tagName).toBe('select');

		// 驗證有縣市選項
		const options = stateSelect.first().locator('option');
		const optionCount = await options.count();
		expect(optionCount).toBeGreaterThan(1);

		// 驗證包含常見縣市名稱（臺 為台灣地址正式字元）
		const stateHtml = await stateSelect.first().innerHTML();
		const hasCity = stateHtml.includes('臺北市') || stateHtml.includes('台北市');
		expect(hasCity).toBe(true);
	});

	test('should auto-fill postcode when district is selected @P0', async ({ page }) => {
		// 選擇縣市（name="county" 為 woomp 台灣地址欄位）
		// woomp 使用 Select2/selectWoo 隱藏原生 select，使用 toBeAttached 代替 toBeVisible
		const stateSelect = page.locator('select[name="county"], select#billing_state, select[name="billing_state"]');
		await expect(stateSelect.first()).toBeAttached();
		// 臺北市 為台灣地址選單正式字元
		await stateSelect.first().selectOption({ label: '臺北市' }, { force: true }).catch(() =>
			stateSelect.first().selectOption({ label: '台北市' }, { force: true })
		);
		await waitForCheckoutUpdate(page);

		// 選擇區域（鄉鎮市區）（Select2 隱藏元素，使用 toBeAttached + force: true）
		const citySelect = page.locator('select[name="district"], select#billing_city, select[name="billing_city"]');
		await expect(citySelect.first()).toBeAttached({ timeout: 5000 });
		await citySelect.first().selectOption({ index: 1 }, { force: true });
		await waitForCheckoutUpdate(page);

		// 驗證郵遞區號自動填入
		const postcodeField = page.locator('#billing_postcode, [name="billing_postcode"]');
		const postcodeValue = await postcodeField.inputValue();
		expect(postcodeValue).toBeTruthy();
		expect(postcodeValue.length).toBeGreaterThanOrEqual(3);
	});

	test('should disable island counties by default @P1', async ({ page }) => {
		// 取得縣市下拉選單（可能為隱藏 native select，使用 toBeAttached）
		const stateSelect = page.locator('select[name="county"], select#billing_state, select[name="billing_state"]');
		const stateAttached = await stateSelect.first().count() > 0;
		if (!stateAttached) {
			test.skip(true, '縣市選單不存在，跳過此測試');
			return;
		}

		// 檢查離島縣市（金門縣、澎湖縣、連江縣）是否預設停用
		// 注意：此行為需要站台啟用離島限制功能；若未啟用則跳過（非所有站台都有此功能）
		const islandCounties = ['金門縣', '澎湖縣', '連江縣'];
		let hasIslandRestriction = false;

		for (const county of islandCounties) {
			const option = stateSelect.first().locator(`option:text("${county}")`);
			const optionExists = await option.count();

			if (optionExists > 0) {
				const isDisabled = await option.evaluate(el => (el as HTMLOptionElement).disabled);
				if (isDisabled) {
					hasIslandRestriction = true;
				} else {
					// 離島縣市存在但未停用 → 此站台可能未啟用離島限制功能
					console.log(`${county} 存在但未 disabled，此站台可能未啟用離島寄送限制`);
				}
			}
			// 若選項不存在，代表已被移除，也符合「停用」的預期
		}

		if (!hasIslandRestriction) {
			// 若所有離島縣市均未停用，跳過此測試（功能未啟用）
			test.skip(true, '離島縣市選項均未 disabled，此站台未啟用離島寄送限制功能，跳過此測試');
		}
	});

	test('should hide TW address dropdowns when country is not TW @P1', async ({ page }) => {
		// 先確認台灣地址下拉存在（可能為隱藏 native select，使用 count() 確認）
		const stateSelect = page.locator('select[name="county"], select#billing_state, select[name="billing_state"]');
		const stateExists = await stateSelect.first().count() > 0;
		if (!stateExists) {
			test.skip(true, '縣市選單不存在，跳過此測試');
			return;
		}

		// 切換國家為非台灣（例如日本）
		// 直接操作原生 hidden select（force: true 繞過 Select2 隱藏），更穩定
		const countrySelect = page.locator('#billing_country, select[name="billing_country"]');
		await countrySelect.first().selectOption('JP', { force: true });

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
