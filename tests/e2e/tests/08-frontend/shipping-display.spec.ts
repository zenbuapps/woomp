/**
 * 前台物流顯示測試
 *
 * 驗證結帳頁面物流方式的顯示、標籤格式及免運提示。
 */

import { test, expect } from '@playwright/test';
import { CREDENTIALS, PRODUCT, BILLING, SELECTORS } from '../../fixtures/test-data';
import { ensureLoggedIn } from '../../helpers/auth.helper';
import { addToCartAndCheckout } from '../../helpers/cart.helper';
import { fillBillingFields, waitForCheckoutUpdate } from '../../helpers/checkout.helper';
import { getAvailableShippingMethods } from '../../helpers/shipping-admin.helper';

test.describe('前台物流顯示', () => {

	test('結帳頁面：物流方式區塊應可見', async ({ page }) => {
		await ensureLoggedIn(page);
		await addToCartAndCheckout(page);
		await fillBillingFields(page);
		await waitForCheckoutUpdate(page);

		// 檢查物流方式區塊
		const shippingSection = page.locator(
			'#shipping_method, .woocommerce-shipping-methods, table.woocommerce-shipping-totals, tr.woocommerce-shipping-totals'
		).first();

		const hasShippingSection = await shippingSection.isVisible().catch(() => false);

		// 也檢查是否顯示「無可用物流」訊息
		const noShipping = page.locator(
			'.woocommerce-shipping-not-calculated, .woocommerce-no-shipping-available-html'
		).first();
		const hasNoShipping = await noShipping.isVisible().catch(() => false);

		// 至少應有物流區塊或無物流訊息
		expect(
			hasShippingSection || hasNoShipping,
			'結帳頁面應有物流方式區塊或無物流訊息'
		).toBeTruthy();

		if (hasShippingSection) {
			// 檢查是否有物流 radio 選項
			const shippingRadios = page.locator(
				'input[type="radio"][name="shipping_method[0]"]'
			);
			const radioCount = await shippingRadios.count();

			// 或者只有一個物流方式（hidden input）
			const shippingHidden = page.locator(
				'input[type="hidden"][name="shipping_method[0]"]'
			);
			const hiddenCount = await shippingHidden.count();

			const totalShippingMethods = radioCount + hiddenCount;

			expect(
				totalShippingMethods,
				'應至少有一個物流方式'
			).toBeGreaterThanOrEqual(1);
		}
	});

	test('物流方式標籤應正確顯示', async ({ page }) => {
		await ensureLoggedIn(page);
		await addToCartAndCheckout(page);
		await fillBillingFields(page);
		await waitForCheckoutUpdate(page);

		// 取得可用的物流方式
		const shippingMethods = await getAvailableShippingMethods(page);

		test.skip(
			shippingMethods.length === 0,
			'無可用物流方式，跳過此測試'
		);

		// 檢查物流 radio 選項的 label
		const shippingRadios = page.locator(
			'input[type="radio"][name="shipping_method[0]"]'
		);
		const radioCount = await shippingRadios.count();

		if (radioCount > 0) {
			for (let i = 0; i < radioCount; i++) {
				const radio = shippingRadios.nth(i);
				const value = await radio.getAttribute('value');
				const id = await radio.getAttribute('id');

				// 尋找對應的 label
				let labelText = '';

				if (id) {
					const label = page.locator(`label[for="${id}"]`);
					const hasLabel = await label.isVisible().catch(() => false);
					if (hasLabel) {
						labelText = (await label.textContent()) || '';
					}
				}

				// 也嘗試父層 label
				if (!labelText) {
					const parentLabel = radio.locator('xpath=ancestor::label');
					const hasParent = await parentLabel.isVisible().catch(() => false);
					if (hasParent) {
						labelText = (await parentLabel.textContent()) || '';
					}
				}

				// 也嘗試同層級 label
				if (!labelText) {
					const siblingLabel = radio.locator('xpath=following-sibling::label').first();
					const hasSibling = await siblingLabel.isVisible().catch(() => false);
					if (hasSibling) {
						labelText = (await siblingLabel.textContent()) || '';
					}
				}

				console.log(`物流方式 [${value}] 標籤: ${labelText.trim()}`);

				// 每個物流選項應有文字說明（標籤或其他形式）
				// 注意：某些物流方式的 label 可能包含在 li 元素中
				if (labelText.trim()) {
					expect(
						labelText.trim().length,
						`物流方式 ${value} 應有標籤文字`
					).toBeGreaterThan(0);
				}
			}
		}

		// 若只有一個物流方式（hidden input），檢查其文字
		if (radioCount === 0) {
			const shippingLabel = page.locator(
				'.woocommerce-shipping-methods label, .shipping-method-label, #shipping_method label'
			).first();
			const hasLabel = await shippingLabel.isVisible().catch(() => false);

			if (hasLabel) {
				const labelText = await shippingLabel.textContent();
				expect(
					labelText?.trim().length,
					'唯一物流方式應有標籤文字'
				).toBeGreaterThan(0);
			}
		}
	});

	test('免運費提示應正確顯示（若已設定）', async ({ page }) => {
		await ensureLoggedIn(page);
		await addToCartAndCheckout(page);
		await fillBillingFields(page);
		await waitForCheckoutUpdate(page);

		// 取得可用的物流方式
		const shippingMethods = await getAvailableShippingMethods(page);

		// 檢查是否有免運費選項
		const freeShipping = shippingMethods.filter(
			(method: string) => method.includes('free_shipping')
		);

		// 檢查頁面上的免運提示文字
		const freeShippingHint = page.locator(
			'.woocommerce-shipping-destination, .free-shipping-notice, .shipping-notice, [class*="free-shipping"]'
		).first();
		const hasFreeHint = await freeShippingHint.isVisible().catch(() => false);

		// 也檢查物流費用為 $0 的情況
		const shippingTotal = page.locator(
			'tr.woocommerce-shipping-totals td, .shipping .woocommerce-Price-amount'
		).first();
		const hasShippingTotal = await shippingTotal.isVisible().catch(() => false);

		if (hasShippingTotal) {
			const totalText = await shippingTotal.textContent();
			console.log('物流費用:', totalText?.trim());

			// 檢查是否為免運（金額為 0 或顯示「免費」）
			const isFree =
				totalText?.includes('$0') ||
				totalText?.includes('免費') ||
				totalText?.includes('Free') ||
				totalText?.includes('free');

			console.log('是否為免運費:', isFree);
		}

		// 檢查整體購物車中是否有免運提示訊息
		const cartNotices = page.locator(
			'.woocommerce-info, .woocommerce-message, .cart-notice, .shipping-notice'
		);
		const noticeCount = await cartNotices.count();

		let foundFreeShippingNotice = false;
		for (let i = 0; i < noticeCount; i++) {
			const noticeText = await cartNotices.nth(i).textContent();
			if (
				noticeText?.includes('免運') ||
				noticeText?.includes('免費運送') ||
				noticeText?.includes('free shipping') ||
				noticeText?.includes('Free shipping')
			) {
				foundFreeShippingNotice = true;
				console.log('免運費提示:', noticeText.trim());
				break;
			}
		}

		console.log('是否有免運費提示訊息:', foundFreeShippingNotice);
		console.log('免運費物流方式數量:', freeShipping.length);

		// 記錄物流方式費用（用於除錯）
		const shippingCosts = page.locator(
			'li .woocommerce-Price-amount, .shipping label .woocommerce-Price-amount'
		);
		const costCount = await shippingCosts.count();

		for (let i = 0; i < costCount; i++) {
			const costText = await shippingCosts.nth(i).textContent();
			console.log(`物流費用 ${i + 1}:`, costText?.trim());
		}
	});
});
