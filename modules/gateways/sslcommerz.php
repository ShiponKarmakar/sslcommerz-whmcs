<?php

/**
 * SSLCommerz payment gateway for WHMCS.
 *
 * Supports two checkout flows:
 *  - Standard redirect: user is sent to pay.sslcommerz.com (the new hosted UI).
 *  - Easy Checkout (popup): SSLCommerz's embed.min.js renders an iframe popup
 *    on desktop. On mobile we automatically fall back to redirect.
 */

const SSLCOMMERZ_API_SANDBOX       = 'https://sandbox.sslcommerz.com/gwprocess/v4/api.php';
const SSLCOMMERZ_API_LIVE          = 'https://securepay.sslcommerz.com/gwprocess/v4/api.php';
const SSLCOMMERZ_REFUND_SANDBOX    = 'https://sandbox.sslcommerz.com/validator/api/merchantTransIDvalidationAPI.php';
const SSLCOMMERZ_REFUND_LIVE       = 'https://securepay.sslcommerz.com/validator/api/merchantTransIDvalidationAPI.php';
const SSLCOMMERZ_BRAND_PURPLE      = '#6c4cf1';
const SSLCOMMERZ_BRAND_GREEN       = '#22c55e';

/**
 * WHMCS 8+ metadata. Declares API version and disables WHMCS's local card-input form.
 */
function sslcommerz_MetaData()
{
    return [
        'DisplayName'                 => 'SSLCommerz',
        'APIVersion'                  => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage'            => false,
    ];
}

function sslcommerz_config()
{
    return [
        'FriendlyName' => ['Type' => 'System', 'Value' => 'SSLCommerz'],
        'username'     => [
            'FriendlyName' => 'Merchant Store ID',
            'Type'         => 'text',
            'Size'         => '100',
            'Description'  => 'Your SSLCommerz merchant account identifier.',
        ],
        'password'     => [
            'FriendlyName' => 'Merchant API Password',
            'Type'         => 'password',
            'Size'         => '100',
            'Description'  => 'API credential issued by SSLCommerz. Treat this like a secret.',
        ],
        'testmode'     => [
            'FriendlyName' => 'Sandbox Mode',
            'Type'         => 'yesno',
            'Description'  => 'Route all transactions through the SSLCommerz sandbox environment so you can test without real charges.',
        ],
        'buttonLabel'  => [
            'FriendlyName' => 'Pay Button Text',
            'Type'         => 'text',
            'Size'         => '100',
            'Default'      => 'Pay Now',
            'Description'  => 'The call-to-action wording shown on the invoice pay button.',
        ],
        'easycheckout' => [
            'FriendlyName' => 'Embedded Checkout Popup',
            'Type'         => 'yesno',
            'Description'  => 'When enabled, payments open in an in-page popup window on desktop. Phones and small screens automatically fall back to a full-page redirect for a better experience.',
        ],
        'force_new_ui' => [
            'FriendlyName' => 'Modern Hosted Page',
            'Type'         => 'yesno',
            'Default'      => 'on',
            'Description'  => 'Routes every payment through <code>pay.sslcommerz.com</code> — the rebuilt SSLCommerz hosted checkout. Recommended for all production stores. Disable only if your account requires the legacy host.',
        ],
    ];
}

/**
 * Map of payment-failure reason codes (passed back via ?reason= on the return page)
 * to friendly user-facing messages. Codes are short and self-descriptive.
 */
function sslcommerz_error_messages()
{
    return [
        'underpaid'    => 'The amount paid was less than the invoice total.',
        'duplicate'    => 'This payment was already processed and cannot be reused.',
        'gateway-bad'  => 'The payment provider returned an unexpected response. Please retry.',
        'incomplete'   => 'The payment was not completed. You can try again from this page.',
        'user-cancel'  => 'You cancelled the payment.',
        'declined'     => 'The payment was declined. Please verify your details and retry.',
        'unknown'      => 'An unexpected error occurred. If this persists, contact support.',
        'amount-mismatch' => 'The paid amount does not match the invoice total.',
        'high-risk'    => 'The transaction was flagged as high-risk and rejected.',
    ];
}

/**
 * Normalize an SSLCommerz hosted-page URL so production traffic always lands on
 * the modern pay.sslcommerz.com host (some store accounts return the legacy
 * securepay.sslcommerz.com URL by default).
 *
 * Implementation uses parse_url() rather than a single regex so the host swap
 * is explicit and easy to audit.
 */
function sslcommerz_normalize_gateway_url($url, $forceNewUi, $isSandbox)
{
    if (!$forceNewUi || $isSandbox || empty($url)) {
        return $url;
    }
    $parts = @parse_url($url);
    if (!$parts || empty($parts['host'])) {
        return $url;
    }
    $host = strtolower($parts['host']);
    // Only swap recognized SSLCommerz production hosts; never touch unrelated URLs.
    if (!preg_match('/(^|\.)sslcommerz\.com$/', $host) || $host === 'pay.sslcommerz.com') {
        return $url;
    }
    $parts['scheme'] = 'https';
    $parts['host']   = 'pay.sslcommerz.com';
    return sslcommerz_build_url($parts);
}

/**
 * Rebuild a URL from the components returned by parse_url().
 */
function sslcommerz_build_url(array $p)
{
    $scheme   = isset($p['scheme'])   ? $p['scheme'] . '://' : '';
    $userinfo = isset($p['user'])     ? $p['user'] . (isset($p['pass']) ? ':' . $p['pass'] : '') . '@' : '';
    $host     = $p['host']            ?? '';
    $port     = isset($p['port'])     ? ':' . $p['port'] : '';
    $path     = $p['path']            ?? '';
    $query    = isset($p['query'])    ? '?' . $p['query'] : '';
    $fragment = isset($p['fragment']) ? '#' . $p['fragment'] : '';
    return $scheme . $userinfo . $host . $port . $path . $query . $fragment;
}

/**
 * WHMCS refund handler. Called from Admin → Invoices → Refund.
 * Uses SSLCommerz's refund API directly via cURL (no Composer vendor needed).
 */
function sslcommerz_refund($params)
{
    $storeId   = $params['username'];
    $storePass = $params['password'];
    $isSandbox = ($params['testmode'] ?? '') === 'on';
    $bankTran  = $params['transid'];
    $amount    = number_format((float) $params['amount'], 2, '.', '');

    $url = $isSandbox ? SSLCOMMERZ_REFUND_SANDBOX : SSLCOMMERZ_REFUND_LIVE;
    $body = http_build_query([
        'bank_tran_id'   => $bankTran,
        'refund_amount'  => $amount,
        'refund_remarks' => 'Refund from WHMCS for transaction ' . $bankTran,
        'store_id'       => $storeId,
        'store_passwd'   => $storePass,
        'v'              => 1,
        'format'         => 'json',
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '?' . $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_errno($ch) ? curl_error($ch) : null;
    curl_close($ch);

    if ($err || $httpCode !== 200) {
        return ['status' => 'error', 'rawdata' => $err ?: 'HTTP ' . $httpCode];
    }
    $data = json_decode($response, true);
    if (!is_array($data) || ($data['APIConnect'] ?? '') !== 'DONE') {
        return ['status' => 'error', 'rawdata' => $response];
    }
    if (!in_array($data['status'] ?? '', ['success', 'initiated', 'processing'], true)) {
        return ['status' => 'error', 'rawdata' => $data];
    }

    return [
        'status'  => 'success',
        'rawdata' => $data,
        'transid' => $data['refund_ref_id'] ?? $bankTran,
        'fees'    => 0,
    ];
}

/**
 * CSS for the branded SweetAlert loader. Shared between both checkout flows.
 */
function sslcommerz_loader_css()
{
    return '
        .sslcz-loader-popup {
            border-radius: 16px !important;
            padding: 32px 28px !important;
            box-shadow: 0 24px 60px rgba(78, 84, 200, 0.25) !important;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
        }
        .sslcz-loader-popup .swal2-title { color: #1a1f36 !important; font-weight: 600 !important; font-size: 22px !important; margin-top: 8px !important; }
        .sslcz-loader-popup .swal2-html-container { color: #4a5568 !important; font-size: 14px !important; margin-top: 6px !important; }
        .sslcz-stage {
            width: 96px; height: 96px; margin: 0 auto 18px;
            position: relative; display: flex; align-items: center; justify-content: center;
        }
        .sslcz-stage svg { width: 44px; height: 44px; color: ' . SSLCOMMERZ_BRAND_PURPLE . '; z-index: 2; }
        .sslcz-stage::before, .sslcz-stage::after {
            content: ""; position: absolute; inset: 0; border-radius: 50%;
            border: 3px solid transparent;
        }
        .sslcz-stage::before {
            border-top-color: ' . SSLCOMMERZ_BRAND_PURPLE . '; border-right-color: ' . SSLCOMMERZ_BRAND_PURPLE . ';
            animation: sslcz-spin 1.1s linear infinite;
        }
        .sslcz-stage::after {
            border-bottom-color: ' . SSLCOMMERZ_BRAND_GREEN . ';
            animation: sslcz-spin 1.6s linear infinite reverse;
            inset: 8px;
        }
        @keyframes sslcz-spin { to { transform: rotate(360deg); } }
        .sslcz-progress {
            width: 100%; height: 4px; background: #eef0f6; border-radius: 4px;
            margin-top: 22px; overflow: hidden; position: relative;
        }
        .sslcz-progress::after {
            content: ""; position: absolute; left: -40%; top: 0; bottom: 0; width: 40%;
            background: linear-gradient(90deg, transparent, ' . SSLCOMMERZ_BRAND_PURPLE . ', transparent);
            animation: sslcz-bar 1.4s ease-in-out infinite;
        }
        @keyframes sslcz-bar { to { left: 100%; } }
        .sslcz-secure {
            margin-top: 16px; font-size: 12px; color: #6b7280; display: flex;
            align-items: center; justify-content: center; gap: 6px;
        }
        .sslcz-secure svg { width: 14px; height: 14px; color: ' . SSLCOMMERZ_BRAND_GREEN . '; }
    ';
}

/**
 * JS string literal that builds the loader HTML body. Shared between both flows.
 */
function sslcommerz_loader_html_js()
{
    return '\'<div class="sslcz-stage">\' +
        \'<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/></svg>\' +
    \'</div>\' +
    \'<div class="sslcz-progress"></div>\' +
    \'<div class="sslcz-secure">\' +
        \'<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z"/></svg>\' +
        \'256-bit SSL secured by SSLCommerz\' +
    \'</div>\'';
}

function sslcommerz_link($params)
{
    // Raw values for API (never HTML-encode outbound payload)
    $storeId    = $params['username'];
    $storePass  = $params['password'];
    $testmode   = $params['testmode'];
    $invoiceid  = (int) $params['invoiceid'];
    $amount     = $params['amount'];
    $currency   = $params['currency'];
    $forceNewUi = !empty($params['force_new_ui']) && $params['force_new_ui'] == 'on';
    $isSandbox  = $testmode == 'on';

    $client = $params['clientdetails'];

    // Use systemurl (admin-configured, trusted) rather than host headers
    $systemurl = rtrim($params['systemurl'], '/') . '/';

    $success_url = $systemurl . 'modules/gateways/callback/sslcommerz.php';
    $fail_url    = $systemurl . 'modules/gateways/callback/sslcommerz.php';
    $ipn_url     = $systemurl . 'modules/gateways/callback/sslcommerz_ipn.php';
    $cancel_url  = $systemurl . 'modules/gateways/callback/sslcommerz.php';

    $gatewayUrl = ($testmode == 'on') ? SSLCOMMERZ_API_SANDBOX : SSLCOMMERZ_API_LIVE;

    // Handle AJAX POST from the pay button. Accepts either "true" (our own JS)
    // or the numeric invoice id (sent by SSLCommerz embed.js).
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['order'])) {
        header('Content-Type: application/json');
        // Belt-and-suspenders: ensure store credentials are configured.
        if ($storeId === '' || $storePass === '') {
            echo json_encode(['status' => 'FAILED', 'message' => 'Gateway is not fully configured. Please contact support.']);
            exit();
        }

        // Unique tran_id per attempt — avoids duplicate-tran_id rejection on retries.
        // Invoice id goes in value_a so the IPN can recover it.
        $tranId = $invoiceid . '-' . bin2hex(random_bytes(4));

        $postData = [
            'store_id'        => $storeId,
            'store_passwd'    => $storePass,
            'tran_id'         => $tranId,
            'total_amount'    => number_format((float) $amount, 2, '.', ''),
            'currency'        => $currency,
            'success_url'     => $success_url,
            'fail_url'        => $fail_url,
            'cancel_url'      => $cancel_url,
            'ipn_url'         => $ipn_url,
            'cus_name'        => trim(($client['firstname'] ?? '') . ' ' . ($client['lastname'] ?? '')),
            'cus_email'       => $client['email'] ?? '',
            'cus_add1'        => $client['address1'] ?? '',
            'cus_add2'        => $client['address2'] ?? '',
            'cus_city'        => $client['city'] ?? '',
            'cus_state'       => $client['state'] ?? '',
            'cus_postcode'    => $client['postcode'] ?? '',
            'cus_country'     => $client['country'] ?? '',
            'cus_phone'       => $client['phonenumber'] ?? '',
            'value_a'         => (string) $invoiceid, // invoice id for IPN lookup
            'value_b'         => $currency,           // expected currency for IPN cross-check
            'shipping_method' => 'NO',
            'num_of_item'     => 1,
            'product_name'    => 'Invoice #' . $invoiceid,
            'product_profile' => 'general',
            'product_category' => 'Domain-Hosting',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $gatewayUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        if ($curlErr || $httpCode !== 200) {
            if (function_exists('logTransaction')) {
                logTransaction('SSLCommerz', ['http' => $httpCode, 'error' => $curlErr, 'tran_id' => $tranId], 'Unsuccessful - API Unreachable');
            }
            echo json_encode(['status' => 'FAILED', 'message' => 'Payment processing failed. Please try again later.']);
            exit();
        }

        // EasyCheckout: the embed.js consumes SSLCommerz's raw response directly.
        // Mobile clients pass mode=redirect so they fall through to the JSON response below.
        if (!empty($params['easycheckout']) && $params['easycheckout'] == 'on'
            && ($_POST['mode'] ?? '') !== 'redirect') {
            $r = json_decode($response, true);
            // Rewrite the GatewayPageURL to force-new-UI if enabled.
            if ($forceNewUi && !$isSandbox && is_array($r) && !empty($r['GatewayPageURL'])) {
                $r['GatewayPageURL'] = sslcommerz_normalize_gateway_url($r['GatewayPageURL'], true, false);
                $response = json_encode($r);
            }
            // Log successful session creation (parity with redirect-mode logging).
            if (is_array($r) && ($r['status'] ?? '') === 'SUCCESS' && function_exists('logTransaction')) {
                logTransaction('SSLCommerz', [
                    'tran_id'    => $tranId,
                    'invoice_id' => $invoiceid,
                    'amount'     => $postData['total_amount'],
                    'currency'   => $currency,
                    'mode'       => 'easycheckout',
                ], 'Payment Session Created');
            }
            echo $response;
            exit();
        }

        $responseObject = json_decode($response, true);
        if (is_array($responseObject) && ($responseObject['status'] ?? '') === 'SUCCESS' && !empty($responseObject['GatewayPageURL'])) {
            // Force the new pay.sslcommerz.com hosted UI in production if requested
            $responseObject['GatewayPageURL'] = sslcommerz_normalize_gateway_url(
                $responseObject['GatewayPageURL'], $forceNewUi, $isSandbox
            );
            if (function_exists('logTransaction')) {
                logTransaction('SSLCommerz', [
                    'tran_id'    => $tranId,
                    'invoice_id' => $invoiceid,
                    'amount'     => $postData['total_amount'],
                    'currency'   => $currency,
                ], 'Payment Session Created');
            }
            echo json_encode([
                'status'         => 'SUCCESS',
                'GatewayPageURL' => $responseObject['GatewayPageURL'],
            ]);
        } else {
            $failedReason = $responseObject['failedreason'] ?? 'Unknown Error';
            if (function_exists('logTransaction')) {
                logTransaction('SSLCommerz', ['response' => $response, 'tran_id' => $tranId], 'Unsuccessful - ' . $failedReason);
            }
            echo json_encode(['status' => 'FAILED', 'message' => $failedReason]);
        }
        exit();
    }

    // ---- Render payment button ----
    $buttonLabel   = htmlspecialchars($params['buttonLabel'] ?? 'Pay Now', ENT_QUOTES, 'UTF-8');
    $invoiceIdHtml = htmlspecialchars((string) $invoiceid, ENT_QUOTES, 'UTF-8');
    $endpoint      = htmlspecialchars($systemurl . 'viewinvoice.php?id=' . $invoiceid, ENT_QUOTES, 'UTF-8');

    $loaderCss     = sslcommerz_loader_css();
    $loaderHtmlJs  = sslcommerz_loader_html_js();

    $easyCheckoutEnabled = !empty($params['easycheckout']) && $params['easycheckout'] == 'on';

    if ($easyCheckoutEnabled) {
        // Mobile users get the full-page redirect (the embed popup is unreliable on small screens).
        // Desktop users get the embedded popup as configured.
        return '
            <style>
                /* Widen + center the SSLCommerz Easy Checkout embed iframe and add a dim backdrop */
                iframe[src*="sslcommerz.com"],
                iframe[src*="seamless-epay"] {
                    position: fixed !important;
                    top: 50% !important;
                    left: 50% !important;
                    transform: translate(-50%, -50%) !important;
                    width: min(960px, 95vw) !important;
                    height: min(820px, 92vh) !important;
                    max-width: 95vw !important;
                    max-height: 92vh !important;
                    border: 0 !important;
                    border-radius: 12px !important;
                    box-shadow: 0 20px 60px rgba(0,0,0,0.35) !important;
                    background: #fff !important;
                    z-index: 2147483647 !important;
                }
                body.sslcz-modal-open::before {
                    content: "";
                    position: fixed;
                    inset: 0;
                    background: rgba(0,0,0,0.6);
                    z-index: 2147483646;
                }
                #sslczCloseBtn {
                    position: fixed;
                    top: 16px;
                    right: 16px;
                    width: 40px;
                    height: 40px;
                    border-radius: 50%;
                    border: 0;
                    background: #fff;
                    color: #222;
                    font-size: 22px;
                    line-height: 1;
                    cursor: pointer;
                    box-shadow: 0 4px 14px rgba(0,0,0,0.45);
                    z-index: 2147483647;
                    display: none;
                }
                body.sslcz-modal-open #sslczCloseBtn { display: flex; align-items: center; justify-content: center; }
                #sslczCloseBtn:hover { background: #f4f4f4; }
                body.sslcz-modal-open { overflow: hidden !important; }
                ' . $loaderCss . '
                @media (max-width: 768px) {
                    iframe[src*="sslcommerz.com"],
                    iframe[src*="seamless-epay"] {
                        top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important;
                        transform: none !important;
                        width: 100% !important; height: 100% !important;
                        max-width: 100% !important; max-height: 100% !important;
                        border-radius: 0 !important; box-shadow: none !important;
                    }
                    body.sslcz-modal-open::before { display: none !important; }
                    #sslczCloseBtn { top: 10px; right: 10px; width: 36px; height: 36px; font-size: 20px; }
                }
            </style>
            <button type="button" class="btn btn-success" id="sslczPayBtn"
                token="" postdata=\'{"paynow":"true"}\'
                order="' . $invoiceIdHtml . '"
                endpoint="' . $endpoint . '">' . $buttonLabel . '</button>
            <button type="button" id="sslczCloseBtn" aria-label="Close payment popup">&times;</button>
            <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
            <script>
                (function (w, d) {
                    var mobileQuery = w.matchMedia("(max-width: 768px)");
                    var isMobile = function () { return mobileQuery.matches; };

                    var loaderHtml = ' . $loaderHtmlJs . ';

                    var swalLoader = function (title, subtext) {
                        if (typeof Swal === "undefined") return null;
                        return Swal.fire({
                            title: title,
                            html: loaderHtml + \'<div style="margin-top:14px;font-size:14px;color:#4a5568;">\' + subtext + \'</div>\',
                            showConfirmButton: false, allowOutsideClick: false, allowEscapeKey: false,
                            customClass: {popup: "sslcz-loader-popup"},
                            showClass: {popup: "swal2-noanimation"},
                            hideClass: {popup: ""}
                        });
                    };

                    var doRedirect = function (payBtn) {
                        payBtn.disabled = true;
                        var hasSwal = typeof Swal !== "undefined";
                        var fail = function (msg) {
                            if (hasSwal) Swal.fire({title: "Payment Failed", text: msg, icon: "error", confirmButtonColor: "' . SSLCOMMERZ_BRAND_PURPLE . '"});
                            else alert(msg);
                            payBtn.disabled = false;
                        };
                        var run = function () {
                            fetch("", {
                                method: "POST",
                                headers: {"Content-Type": "application/x-www-form-urlencoded"},
                                body: "order=true&mode=redirect",
                                credentials: "same-origin"
                            })
                            .then(function (r) { return r.json(); })
                            .then(function (data) {
                                if (data.status === "SUCCESS" && data.GatewayPageURL) w.location.href = data.GatewayPageURL;
                                else fail(data.message || "An error occurred.");
                            })
                            .catch(function (err) { fail(String(err)); });
                        };
                        if (hasSwal) swalLoader("Redirecting to Secure Payment", "Please don\\\'t close this window…").then(function(){});
                        // run after Swal is shown (didOpen would re-fire on update; just trigger directly):
                        run();
                    };

                    var bindMobile = function () {
                        var payBtn = d.getElementById("sslczPayBtn");
                        var closeBtn = d.getElementById("sslczCloseBtn");
                        if (closeBtn) closeBtn.style.display = "none";
                        if (!payBtn) return;
                        payBtn.addEventListener("click", function (e) {
                            e.preventDefault();
                            e.stopImmediatePropagation();
                            doRedirect(payBtn);
                        }, true);
                    };

                    if (isMobile()) {
                        if (d.readyState === "loading") d.addEventListener("DOMContentLoaded", bindMobile);
                        else bindMobile();
                        return; // do not load embed.js on mobile
                    }

                    // Desktop popup mode — show branded loader on click while embed.js fetches the iframe URL
                    var bindDesktopLoader = function () {
                        var payBtn = d.getElementById("sslczPayBtn");
                        if (!payBtn) return;
                        payBtn.addEventListener("click", function () {
                            swalLoader("Opening Secure Checkout", "Loading payment options…");
                        }, true);
                    };
                    if (d.readyState === "loading") d.addEventListener("DOMContentLoaded", bindDesktopLoader);
                    else bindDesktopLoader();

                    // Load SSLCommerz embed.js after page load
                    var loader = function () {
                        var s = d.createElement("script"), t = d.getElementsByTagName("script")[0];
                        s.src = "https://seamless-epay.sslcommerz.com/embed.min.js?" + Math.random().toString(36).substring(7);
                        t.parentNode.insertBefore(s, t);
                    };
                    w.addEventListener ? w.addEventListener("load", loader, false) : w.attachEvent("onload", loader);

                    var iframeSelector = "iframe[src*=\'sslcommerz.com\'], iframe[src*=\'seamless-epay\']";

                    var isSslczNode = function (el) {
                        if (!el || el === d.body || el === d.documentElement) return false;
                        var sig = (el.id || "") + " " + (el.className && el.className.toString ? el.className.toString() : "");
                        return /sslcz|sslcommerz|epay/i.test(sig);
                    };

                    var styleWrapper = function (frame) {
                        if (!isMobile()) return;
                        var node = frame.parentElement;
                        while (node && node !== d.body) {
                            if (!isSslczNode(node)) { node = node.parentElement; continue; }
                            node.style.background = "transparent";
                            node.style.position = "fixed";
                            node.style.inset = "0";
                            node.style.width = "100%"; node.style.height = "100%";
                            node.style.padding = "0"; node.style.margin = "0";
                            node = node.parentElement;
                        }
                    };

                    var closePopup = function () { w.location.reload(); };

                    var applyClass = function () {
                        var frames = d.querySelectorAll(iframeSelector);
                        var has = frames.length > 0;
                        d.body.classList.toggle("sslcz-modal-open", has);
                        frames.forEach(styleWrapper);
                        if (has && typeof Swal !== "undefined" && Swal.isVisible && Swal.isVisible()) Swal.close();
                    };
                    var mo = new MutationObserver(applyClass);
                    w.addEventListener("load", function () {
                        mo.observe(d.body, {childList: true, subtree: true, attributes: true, attributeFilter: ["style", "class"]});
                        var btn = d.getElementById("sslczCloseBtn");
                        if (btn) btn.addEventListener("click", closePopup);
                        d.addEventListener("keydown", function (e) {
                            if (e.key === "Escape" && d.body.classList.contains("sslcz-modal-open")) closePopup();
                        });
                    });
                })(window, document);
            </script>
        ';
    }

    // ---- Standard redirect mode ----
    return '
        <button type="button" class="btn btn-success" id="sslcommerz-pay-button">' . $buttonLabel . '</button>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <style>' . $loaderCss . '</style>
        <script>
            (function () {
                var btn = document.getElementById("sslcommerz-pay-button");
                if (!btn) return;
                var loaderHtml = ' . $loaderHtmlJs . ';

                btn.addEventListener("click", function (e) {
                    e.preventDefault();
                    btn.disabled = true;
                    var hasSwal = typeof Swal !== "undefined";
                    var fail = function (msg) {
                        if (hasSwal) Swal.fire({title: "Payment Failed", text: msg, icon: "error", confirmButtonColor: "' . SSLCOMMERZ_BRAND_PURPLE . '"});
                        else alert(msg);
                        btn.disabled = false;
                    };
                    var run = function () {
                        fetch("", {
                            method: "POST",
                            headers: {"Content-Type": "application/x-www-form-urlencoded"},
                            body: "order=true",
                            credentials: "same-origin"
                        })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (data.status === "SUCCESS" && data.GatewayPageURL) window.location.href = data.GatewayPageURL;
                            else fail(data.message || "An error occurred.");
                        })
                        .catch(function (err) { fail(String(err)); });
                    };
                    if (hasSwal) {
                        Swal.fire({
                            title: "Redirecting to Secure Payment",
                            html: loaderHtml + \'<div style="margin-top:14px;font-size:14px;color:#4a5568;">Please don\\\'t close this window…</div>\',
                            showConfirmButton: false, allowOutsideClick: false, allowEscapeKey: false,
                            customClass: {popup: "sslcz-loader-popup"},
                            showClass: {popup: "swal2-noanimation"},
                            hideClass: {popup: ""},
                            didOpen: function () { run(); }
                        });
                    } else { run(); }
                });
            })();
        </script>
    ';
}
