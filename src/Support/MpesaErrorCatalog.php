<?php

namespace Harri\LaravelMpesa\Support;

use Harri\LaravelMpesa\Models\MpesaErrorCode;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MpesaErrorCatalog
{
    public static function syncKnownDefinitions(): void
    {
        if (! self::tableExists()) {
            return;
        }

        foreach (self::definitions() as $definition) {
            $now = now();

            MpesaErrorCode::query()->updateOrCreate([
                'signature' => self::signature(
                    $definition['journey'] ?? null,
                    $definition['stage'] ?? 'request',
                    $definition['code'] ?? null,
                    $definition['message'] ?? null,
                    $definition['http_status'] ?? null,
                ),
            ], [
                'source' => 'daraja',
                'journey' => $definition['journey'] ?? null,
                'error_stage' => $definition['stage'] ?? 'request',
                'code' => $definition['code'] ?? null,
                'error_key' => $definition['error_key'] ?? 'mpesa_request_failed',
                'http_status' => $definition['http_status'] ?? null,
                'title' => $definition['title'] ?? null,
                'message' => $definition['message'] ?? null,
                'possible_cause' => $definition['possible_cause'] ?? null,
                'mitigation' => $definition['mitigation'] ?? null,
                'is_known' => true,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'sample_payload' => null,
            ]);
        }
    }

    public static function record(
        int $status,
        array $details = [],
        ?string $fallbackMessage = null,
        ?string $journey = null,
        string $stage = 'request',
    ): ?MpesaErrorCode {
        if (! self::tableExists()) {
            return null;
        }

        self::syncKnownDefinitions();

        $code = self::extractCode($details, $fallbackMessage);
        $message = self::extractMessage($details, $fallbackMessage);
        $signature = self::signature($journey, $stage, $code, $message, $status);
        $match = self::matchKnownDefinition($code, $message, $status, $journey, $stage);
        $now = now();

        $record = MpesaErrorCode::query()->firstOrNew(['signature' => $signature]);
        $record->fill([
            'source' => 'daraja',
            'journey' => $journey,
            'error_stage' => $stage,
            'code' => $code,
            'error_key' => $match['error_key'] ?? ($record->error_key ?: 'mpesa_request_failed'),
            'http_status' => $status,
            'title' => $match['title'] ?? ($record->title ?: null),
            'message' => $message,
            'possible_cause' => $match['possible_cause'] ?? ($record->possible_cause ?: null),
            'mitigation' => $match['mitigation'] ?? ($record->mitigation ?: null),
            'is_known' => $match !== null,
            'sample_payload' => $details === [] ? null : $details,
        ]);

        if (! $record->exists) {
            $record->occurrences = 0;
            $record->first_seen_at = $now;
        }

        $record->last_seen_at = $now;
        $record->occurrences = (int) $record->occurrences + 1;
        $record->save();

        return $record;
    }

    public static function extractCode(array $details = [], ?string $fallbackMessage = null): ?string
    {
        foreach (['errorCode', 'ErrorCode', 'errorcode', 'ResponseCode', 'responseCode', 'ResultCode', 'resultCode'] as $key) {
            $value = Arr::get($details, $key);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        $message = self::extractMessage($details, $fallbackMessage);

        if (preg_match('/\b\d{3}\.\d{3}\.\d{2,4}\b/', $message, $matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    public static function extractMessage(array $details = [], ?string $fallbackMessage = null): string
    {
        foreach (['errorMessage', 'ErrorMessage', 'errormessage', 'ResponseDescription', 'responseDescription', 'ResultDesc', 'resultDesc'] as $key) {
            $value = Arr::get($details, $key);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return trim((string) ($fallbackMessage ?? 'M-Pesa request failed.'));
    }

    protected static function matchKnownDefinition(?string $code, string $message, int $status, ?string $journey = null, string $stage = 'request'): ?array
    {
        $normalizedMessage = self::normalizeText($message);

        foreach (self::definitions() as $definition) {
            if (($definition['http_status'] ?? null) !== $status) {
                continue;
            }

            if (($definition['stage'] ?? 'request') !== $stage) {
                continue;
            }

            if (($definition['code'] ?? null) !== $code) {
                continue;
            }

            if (($definition['journey'] ?? null) !== null && ($definition['journey'] ?? null) !== $journey) {
                continue;
            }

            $knownMessage = self::normalizeText((string) ($definition['message'] ?? ''));

            if ($knownMessage === '' || str_contains($normalizedMessage, $knownMessage) || str_contains($knownMessage, $normalizedMessage)) {
                return $definition;
            }
        }

        foreach (self::definitions() as $definition) {
            if (($definition['code'] ?? null) === $code
                && ($definition['http_status'] ?? null) === $status
                && ($definition['stage'] ?? 'request') === $stage
                && (($definition['journey'] ?? null) === null || ($definition['journey'] ?? null) === $journey)) {
                return $definition;
            }
        }

        return null;
    }

    protected static function definitions(): array
    {
        return [
            [
                'journey' => null,
                'stage' => 'request',
                'http_status' => 500,
                'code' => '500.003.1001',
                'error_key' => 'mpesa_internal_server_error',
                'title' => 'Internal Server Error',
                'message' => 'Internal Server Error',
                'possible_cause' => 'Server failure.',
                'mitigation' => 'Make sure everything on your side is correctly set up as per the API, call the correct endpoint, and confirm your server is running as expected.',
            ],
            [
                'journey' => 'c2b',
                'stage' => 'request',
                'http_status' => 500,
                'code' => '500.003.1001',
                'error_key' => 'mpesa_c2b_urls_already_registered',
                'title' => 'URLs are already registered',
                'message' => 'Urls are already registered.',
                'possible_cause' => 'There is an existing URL registered.',
                'mitigation' => 'If you want to change them, request deletion of the existing URLs and then register again.',
            ],
            [
                'journey' => 'c2b',
                'stage' => 'request',
                'http_status' => 500,
                'code' => '500.003.1001',
                'error_key' => 'mpesa_duplicate_notification_info',
                'title' => 'Duplicate notification info',
                'message' => 'Duplicate notification info',
                'possible_cause' => 'URLs already exist on the aggregator platform, so they cannot also be registered on Daraja.',
                'mitigation' => 'Request deletion of the URLs from the aggregator platform first, then proceed with Daraja registration.',
            ],
            [
                'journey' => 'stk_query',
                'stage' => 'request',
                'http_status' => 404,
                'code' => '404.001.04',
                'error_key' => 'mpesa_stk_query_invalid_authentication_header',
                'title' => 'Invalid Authentication Header',
                'message' => 'Invalid Authentication Header',
                'possible_cause' => 'Headers or HTTP method are incorrect. Daraja APIs are POST except the Authorization API which is GET.',
                'mitigation' => 'Send the STK query request as POST and place the Authorization header correctly.',
            ],
            [
                'journey' => 'stk_query',
                'stage' => 'request',
                'http_status' => 400,
                'code' => '400.002.05',
                'error_key' => 'mpesa_stk_query_invalid_request_payload',
                'title' => 'Invalid Request Payload',
                'message' => 'Invalid Request Payload',
                'possible_cause' => 'The STK query request body is not properly drafted.',
                'mitigation' => 'Submit BusinessShortCode, Password, Timestamp, and CheckoutRequestID exactly as required by Daraja.',
            ],
            [
                'journey' => 'stk_query',
                'stage' => 'request',
                'http_status' => 400,
                'code' => '400.003.01',
                'error_key' => 'mpesa_stk_query_invalid_access_token',
                'title' => 'Invalid Access Token',
                'message' => 'Invalid Access Token',
                'possible_cause' => 'A wrong or expired access token was used for the STK query request.',
                'mitigation' => 'Regenerate a new token and use it before expiry.',
            ],
            [
                'journey' => 'stk_query',
                'stage' => 'query_result',
                'http_status' => 200,
                'code' => '1032',
                'error_key' => 'mpesa_stk_query_request_cancelled_by_user',
                'title' => 'Request cancelled by user',
                'message' => 'Request cancelled by user',
                'possible_cause' => 'The original STK prompt was cancelled by the customer.',
                'mitigation' => 'Ask the customer to retry and complete the prompt on their phone.',
            ],
            [
                'journey' => 'stk',
                'stage' => 'request',
                'http_status' => 400,
                'code' => '400.002.02',
                'error_key' => 'mpesa_stk_invalid_request_field',
                'title' => 'Invalid STK request field',
                'message' => 'Bad Request - Invalid',
                'possible_cause' => 'One or more STK request fields such as BusinessShortCode, PartyA, PartyB, PhoneNumber, or TransactionType are invalid.',
                'mitigation' => 'Ensure the STK payload matches the Daraja specification and that all values are valid for the selected transaction type.',
            ],
            [
                'journey' => 'stk',
                'stage' => 'request',
                'http_status' => 404,
                'code' => '404.001.03',
                'error_key' => 'mpesa_stk_invalid_access_token',
                'title' => 'Invalid Access Token',
                'message' => 'Invalid Access Token',
                'possible_cause' => 'A wrong or expired access token was used for the STK request.',
                'mitigation' => 'Regenerate a new access token, verify your consumer key and secret, and use the token before expiry.',
            ],
            [
                'journey' => 'stk',
                'stage' => 'request',
                'http_status' => 404,
                'code' => '404.001.01',
                'error_key' => 'mpesa_stk_resource_not_found',
                'title' => 'Resource not found',
                'message' => 'Resource not found',
                'possible_cause' => 'The STK endpoint path is incorrect or the wrong environment is being used.',
                'mitigation' => 'Confirm you are calling the correct STK endpoint for the target environment.',
            ],
            [
                'journey' => 'stk',
                'stage' => 'request',
                'http_status' => 405,
                'code' => '405.001',
                'error_key' => 'mpesa_stk_method_not_allowed',
                'title' => 'Method Not Allowed',
                'message' => 'Method Not Allowed',
                'possible_cause' => 'The STK endpoint was called with a method other than POST.',
                'mitigation' => 'Send the STK request as POST.',
            ],
            [
                'journey' => 'stk',
                'stage' => 'request',
                'http_status' => 500,
                'code' => '500.001.1001',
                'error_key' => 'mpesa_stk_merchant_does_not_exist',
                'title' => 'Merchant does not exist',
                'message' => 'Merchant does not exist',
                'possible_cause' => 'The BusinessShortCode is not recognized for the target environment.',
                'mitigation' => 'Use the correct go-live or sandbox shortcode for the STK request.',
            ],
            [
                'journey' => 'stk',
                'stage' => 'request',
                'http_status' => 500,
                'code' => '500.001.1001',
                'error_key' => 'mpesa_stk_wrong_credentials',
                'title' => 'Wrong credentials',
                'message' => 'Wrong credentials',
                'possible_cause' => 'The Password is invalid, missing, or does not match the BusinessShortCode and Timestamp used in the request.',
                'mitigation' => 'Rebuild the Password using base64(Shortcode + Passkey + Timestamp) and ensure the shortcode and timestamp match the request body.',
            ],
            [
                'journey' => 'stk',
                'stage' => 'request',
                'http_status' => 500,
                'code' => '500.001.1001',
                'error_key' => 'mpesa_stk_subscriber_locked',
                'title' => 'Subscriber already has a transaction in process',
                'message' => 'Unable to lock subscriber, a transaction is already in process for the current subscriber',
                'possible_cause' => 'Another STK session is already active for the same customer.',
                'mitigation' => 'Wait at least one minute before retrying another STK push for the same customer.',
            ],
            [
                'journey' => 'stk',
                'stage' => 'request',
                'http_status' => 500,
                'code' => '500.003.02',
                'error_key' => 'mpesa_stk_system_busy',
                'title' => 'System busy',
                'message' => 'System is busy. Please try again in few minutes.',
                'possible_cause' => 'Daraja or downstream M-PESA services are temporarily busy.',
                'mitigation' => 'Retry the STK request after a short wait.',
            ],
            [
                'journey' => 'stk',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => '1',
                'error_key' => 'mpesa_stk_insufficient_balance',
                'title' => 'Insufficient balance',
                'message' => 'The balance is insufficient for the transaction.',
                'possible_cause' => 'The customer does not have enough money in their M-PESA account.',
                'mitigation' => 'Ask the customer to top up their wallet or use a lower amount.',
            ],
            [
                'journey' => 'stk',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => '2',
                'error_key' => 'mpesa_stk_below_minimum_amount',
                'title' => 'Below minimum amount',
                'message' => 'Declined due to limit rule.',
                'possible_cause' => 'The amount is less than the allowed C2B minimum.',
                'mitigation' => 'Increase the amount to the allowed minimum.',
            ],
            [
                'journey' => 'stk',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => '3',
                'error_key' => 'mpesa_stk_above_maximum_amount',
                'title' => 'Above maximum amount',
                'message' => 'Declined due to limit rule: greater than the maximum transaction amount.',
                'possible_cause' => 'The amount exceeds the allowed STK transaction maximum.',
                'mitigation' => 'Reduce the amount to within the allowed transaction limit.',
            ],
            [
                'journey' => 'stk',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => '4',
                'error_key' => 'mpesa_stk_daily_limit_exceeded',
                'title' => 'Daily transfer limit exceeded',
                'message' => 'Declined due to limit rule: would exceed daily transfer limit.',
                'possible_cause' => 'The transaction would exceed the customer daily transfer limit.',
                'mitigation' => 'Retry later or lower the amount.',
            ],
            [
                'journey' => 'stk',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => '8',
                'error_key' => 'mpesa_stk_maximum_balance_exceeded',
                'title' => 'Maximum balance exceeded',
                'message' => 'Declined due to limit rule: would exceed the maximum balance.',
                'possible_cause' => 'The transaction would push the pay bill or till balance past the allowed maximum.',
                'mitigation' => 'Use a lower amount or confirm the receiving account can accept the funds.',
            ],
            [
                'journey' => 'stk',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => '17',
                'error_key' => 'mpesa_stk_rule_limited',
                'title' => 'Rule limited',
                'message' => 'Rule limited.',
                'possible_cause' => 'Repeated STK requests were initiated in quick succession for the same amount and customer.',
                'mitigation' => 'Wait at least two minutes before retrying the same request pattern.',
            ],
            [
                'journey' => 'stk',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => '1019',
                'error_key' => 'mpesa_stk_transaction_expired',
                'title' => 'Transaction expired',
                'message' => 'Transaction has expired.',
                'possible_cause' => 'The STK transaction was not completed within the allowable time.',
                'mitigation' => 'Retry the transaction and ensure the customer is ready to complete the prompt in time.',
            ],
            [
                'journey' => 'stk',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => '1025',
                'error_key' => 'mpesa_stk_push_dispatch_error',
                'title' => 'Push dispatch error',
                'message' => 'An error occurred while sending a push request.',
                'possible_cause' => 'The prompt content is too long, commonly because AccountReference or related fields exceed the allowed length.',
                'mitigation' => 'Keep AccountReference and TransactionDesc within Daraja length limits and retry.',
            ],
            [
                'journey' => 'stk',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => '1032',
                'error_key' => 'mpesa_stk_request_cancelled_by_user',
                'title' => 'Request cancelled by user',
                'message' => 'Request cancelled by user',
                'possible_cause' => 'The customer cancelled the STK prompt before entering their PIN.',
                'mitigation' => 'Ask the customer to retry and complete the prompt on their phone.',
            ],
            [
                'journey' => 'stk',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => '1037',
                'error_key' => 'mpesa_stk_ds_timeout_user_unreachable',
                'title' => 'User cannot be reached',
                'message' => 'DS timeout user cannot be reached.',
                'possible_cause' => 'The customer phone is offline, busy, or in another ongoing session.',
                'mitigation' => 'Ask the customer to confirm the phone is on, has network coverage, and is not busy, then retry.',
            ],
            [
                'journey' => 'stk',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => '2001',
                'error_key' => 'mpesa_stk_initiator_information_invalid',
                'title' => 'Incorrect customer PIN',
                'message' => 'The initiator information is invalid.',
                'possible_cause' => 'The customer entered an incorrect M-PESA PIN.',
                'mitigation' => 'Advise the customer to retry using the correct M-PESA PIN.',
            ],
            [
                'journey' => 'stk',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => '2028',
                'error_key' => 'mpesa_stk_product_assignment_not_permitted',
                'title' => 'Request not permitted for product assignment',
                'message' => 'The request is not permitted according to product assignment.',
                'possible_cause' => 'The TransactionType or PartyB does not match the configured pay bill or till product.',
                'mitigation' => 'Use CustomerBuyGoodsOnline with a till and CustomerPayBillOnline with a pay bill, and ensure PartyB matches the correct receiving number.',
            ],
            [
                'journey' => 'stk',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => '8006',
                'error_key' => 'mpesa_stk_security_credential_locked',
                'title' => 'Security credential locked',
                'message' => 'The security credential is locked.',
                'possible_cause' => 'The customer wallet security state requires support intervention.',
                'mitigation' => 'Ask the customer to contact Customer Care for assistance.',
            ],
            [
                'journey' => 'stk',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => 'SFC_IC0003',
                'error_key' => 'mpesa_stk_operator_does_not_exist',
                'title' => 'Operator does not exist',
                'message' => 'The operator does not exist.',
                'possible_cause' => 'The transaction type or receiving shortcode details are incorrect for the requested STK flow.',
                'mitigation' => 'Verify PartyB and TransactionType match the correct pay bill or till setup.',
            ],
            [
                'journey' => 'qr',
                'stage' => 'request',
                'http_status' => 404,
                'code' => '404.001.04',
                'error_key' => 'mpesa_qr_invalid_authentication_header',
                'title' => 'Invalid Authentication Header',
                'message' => 'Invalid Authentication Header',
                'possible_cause' => 'Headers or HTTP method are incorrect. Daraja APIs are POST except the Authorization API which is GET.',
                'mitigation' => 'Use POST for the QR API request and place the Authorization header correctly.',
            ],
            [
                'journey' => 'qr',
                'stage' => 'request',
                'http_status' => 400,
                'code' => '400.002.05',
                'error_key' => 'mpesa_qr_invalid_request_payload',
                'title' => 'Invalid Request Payload',
                'message' => 'Invalid Request Payload',
                'possible_cause' => 'The QR request body is not properly drafted.',
                'mitigation' => 'Submit the QR payload exactly as required, including MerchantName, RefNo, Amount, TrxCode, CPI, and Size where needed.',
            ],
            [
                'journey' => 'qr',
                'stage' => 'request',
                'http_status' => 400,
                'code' => '400.003.01',
                'error_key' => 'mpesa_qr_invalid_access_token',
                'title' => 'Invalid Access Token',
                'message' => 'Invalid Access Token',
                'possible_cause' => 'A wrong or expired access token was used for the QR API request.',
                'mitigation' => 'Regenerate a new token and use it before expiry. If you are copying it manually, confirm you pasted the correct token.',
            ],
            [
                'journey' => 'b2c',
                'stage' => 'request',
                'http_status' => 500,
                'code' => '500.002.1001',
                'error_key' => 'mpesa_b2c_duplicate_originator_conversation_id',
                'title' => 'Duplicate OriginatorConversationID',
                'message' => 'Duplicate OriginatorConversationID.',
                'possible_cause' => 'The same OriginatorConversationID has already been used for another B2C request.',
                'mitigation' => 'Generate a unique OriginatorConversationID for each B2C request to avoid double disbursement.',
            ],
            [
                'journey' => null,
                'stage' => 'request',
                'http_status' => 400,
                'code' => '400.003.01',
                'error_key' => 'mpesa_invalid_access_token',
                'title' => 'Invalid Access Token',
                'message' => 'Invalid Access Token',
                'possible_cause' => 'A wrong or expired access token was used.',
                'mitigation' => 'Regenerate a new token and use it before expiry. If you are copying it manually, confirm you pasted the correct token.',
            ],
            [
                'journey' => null,
                'stage' => 'request',
                'http_status' => 400,
                'code' => '400.003.02',
                'error_key' => 'mpesa_bad_request',
                'title' => 'Bad Request',
                'message' => 'Bad Request',
                'possible_cause' => 'The server cannot process the request because something is missing.',
                'mitigation' => 'Make sure everything on your side is correctly set up as per the API documentation.',
            ],
            [
                'journey' => null,
                'stage' => 'request',
                'http_status' => 500,
                'code' => '500.003.03',
                'error_key' => 'mpesa_quota_violation',
                'title' => 'Quota Violation',
                'message' => 'Quota Violation',
                'possible_cause' => 'You are sending multiple requests that violate M-PESA transaction-per-second limits.',
                'mitigation' => 'For testing, send a reasonable number of requests and preferably one request at a time.',
            ],
            [
                'journey' => null,
                'stage' => 'request',
                'http_status' => 500,
                'code' => '500.003.02',
                'error_key' => 'mpesa_spike_arrest_violation',
                'title' => 'Spike Arrest Violation',
                'message' => 'Spike Arrest Violation',
                'possible_cause' => 'Your endpoints are constantly generating errors that create a spike and affect platform performance.',
                'mitigation' => 'Make sure your endpoints and server are running, accessible over the internet, and responding as expected.',
            ],
            [
                'journey' => null,
                'stage' => 'request',
                'http_status' => 404,
                'code' => '404.003.01',
                'error_key' => 'mpesa_resource_not_found',
                'title' => 'Resource not found',
                'message' => 'Resource not found',
                'possible_cause' => 'The requested resource could not be found.',
                'mitigation' => 'Make sure you are calling the correct M-PESA API endpoint.',
            ],
            [
                'journey' => null,
                'stage' => 'request',
                'http_status' => 404,
                'code' => '404.001.04',
                'error_key' => 'mpesa_invalid_authenticator_header',
                'title' => 'Invalid Authenticator Header',
                'message' => 'Invalid Authenticator Header',
                'possible_cause' => 'Headers or HTTP method are incorrect. Daraja APIs are POST except the Authorization API which is GET.',
                'mitigation' => 'Use POST for all Daraja API requests except the Authorization API, and place headers correctly.',
            ],
            [
                'journey' => null,
                'stage' => 'request',
                'http_status' => 400,
                'code' => '400.002.05',
                'error_key' => 'mpesa_invalid_request_payload',
                'title' => 'Invalid Request Payload',
                'message' => 'Invalid Request Payload',
                'possible_cause' => 'Your request body is not properly drafted.',
                'mitigation' => 'Submit the correct request payload shown in the sample body and double-check for typos.',
            ],
            [
                'journey' => 'b2c',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => '1',
                'error_key' => 'mpesa_b2c_insufficient_balance',
                'title' => 'Insufficient balance',
                'message' => 'The balance is insufficient for the transaction.',
                'possible_cause' => 'The B2C utility account does not have enough money to complete the requested transaction.',
                'mitigation' => 'Top up the utility account or lower the amount before retrying.',
            ],
            [
                'journey' => 'b2c',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => '2',
                'error_key' => 'mpesa_b2c_below_limit_rule',
                'title' => 'Declined due to limit rule',
                'message' => 'Declined due to limit rule',
                'possible_cause' => 'The amount provided is smaller than the allowed B2C transaction amount.',
                'mitigation' => 'Increase the amount to the allowed minimum for the B2C product.',
            ],
            [
                'journey' => 'b2c',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => '3',
                'error_key' => 'mpesa_b2c_above_maximum_transaction_amount',
                'title' => 'Above maximum transaction amount',
                'message' => 'Declined due to limit rule: greater than the maximum transaction amount.',
                'possible_cause' => 'The amount provided is greater than the allowed B2C transaction amount.',
                'mitigation' => 'Reduce the amount to within the allowed B2C transaction limit.',
            ],
            [
                'journey' => 'b2c',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => '4',
                'error_key' => 'mpesa_b2c_daily_transfer_limit_exceeded',
                'title' => 'Daily transfer limit exceeded',
                'message' => 'Declined due to limit rule: would exceed daily transfer limit',
                'possible_cause' => 'The transaction would exceed the daily transfer limit for the business or the recipient.',
                'mitigation' => 'Try again later or reduce the amount so the daily transfer limit is not exceeded.',
            ],
            [
                'journey' => 'b2c',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => '8',
                'error_key' => 'mpesa_b2c_maximum_balance_exceeded',
                'title' => 'Maximum balance exceeded',
                'message' => 'Declined due to limit rule: would exceed the maximum balance.',
                'possible_cause' => 'Processing the transaction would exceed the recipient account balance limit.',
                'mitigation' => 'Reduce the transaction amount or confirm the recipient can receive the requested funds.',
            ],
            [
                'journey' => 'b2c',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => '11',
                'error_key' => 'mpesa_b2c_debit_party_invalid_state',
                'title' => 'Debit party in invalid state',
                'message' => 'The DebitParty is in an invalid state.',
                'possible_cause' => 'The B2C account is not active.',
                'mitigation' => 'Confirm the shortcode account is active and allowed to disburse.',
            ],
            [
                'journey' => 'b2c',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => '21',
                'error_key' => 'mpesa_b2c_initiator_not_allowed',
                'title' => 'Initiator not allowed',
                'message' => 'The initiator is not allowed to initiate this request',
                'possible_cause' => 'The API user does not have the ORG B2C initiator role required for B2C disbursements.',
                'mitigation' => 'Assign the correct B2C initiator role to the API user on the M-PESA portal.',
            ],
            [
                'journey' => 'b2c',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => '2001',
                'error_key' => 'mpesa_b2c_initiator_information_invalid',
                'title' => 'Initiator information invalid',
                'message' => 'The initiator information is invalid.',
                'possible_cause' => 'The API user credentials are invalid. The username may be wrong, the password may be encrypted incorrectly, or the wrong certificate/algorithm may have been used.',
                'mitigation' => 'Verify the InitiatorName, encrypt the correct password with the correct certificate, and confirm the algorithm matches Daraja requirements.',
            ],
            [
                'journey' => 'b2c',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => '2006',
                'error_key' => 'mpesa_b2c_account_status_disallows_transaction',
                'title' => 'Account status disallows transaction',
                'message' => 'Declined due to account rule: The account status does not allow this transaction.',
                'possible_cause' => 'The B2C account is not active.',
                'mitigation' => 'Activate the B2C account or resolve account restrictions before retrying.',
            ],
            [
                'journey' => 'b2c',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => '2028',
                'error_key' => 'mpesa_b2c_product_assignment_not_permitted',
                'title' => 'Product assignment not permitted',
                'message' => 'The request is not permitted according to product assignment.',
                'possible_cause' => 'The PartyA shortcode has no permission to perform B2C payments.',
                'mitigation' => 'Confirm the shortcode has the correct B2C product enabled before retrying.',
            ],
            [
                'journey' => 'b2c',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => '2040',
                'error_key' => 'mpesa_b2c_credit_party_customer_type_unsupported',
                'title' => 'Credit party customer type unsupported',
                'message' => "Credit Party customer type (Unregistered or Registered Customer) can't be supported by the service.",
                'possible_cause' => 'The recipient is not eligible for the requested B2C service, commonly because the customer is not registered.',
                'mitigation' => 'Confirm the recipient is a registered M-PESA customer and that the service supports that customer type.',
            ],
            [
                'journey' => 'b2c',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => '8006',
                'error_key' => 'mpesa_b2c_security_credential_locked',
                'title' => 'Security credential locked',
                'message' => 'The security credential is locked',
                'possible_cause' => 'The API user password has been locked.',
                'mitigation' => 'Have the Business Administrator unlock the API user password.',
            ],
            [
                'journey' => 'b2c',
                'stage' => 'callback_result',
                'http_status' => 200,
                'code' => 'SFC_IC0003',
                'error_key' => 'mpesa_b2c_operator_does_not_exist',
                'title' => 'Operator does not exist',
                'message' => 'The operator does not exist.',
                'possible_cause' => 'The phone number provided in the request is invalid or does not exist on M-PESA.',
                'mitigation' => 'Validate the recipient phone number and retry with a valid M-PESA number.',
            ],
            [
                'journey' => null,
                'stage' => 'timeout',
                'http_status' => 200,
                'code' => '1037',
                'error_key' => 'mpesa_request_timed_out',
                'title' => 'Request timed out',
                'message' => 'Timed out',
                'possible_cause' => 'M-PESA did not complete the asynchronous request before the timeout callback was triggered.',
                'mitigation' => 'Query the transaction status before retrying and ensure your callback endpoints are reachable.',
            ],
        ];
    }

    protected static function signature(?string $journey, string $stage, ?string $code, ?string $message, ?int $status): string
    {
        $parts = [
            trim((string) ($journey ?? 'unknown_journey')),
            trim($stage),
            trim((string) ($code ?? 'unknown')),
            (string) ($status ?? 0),
            self::normalizeText((string) ($message ?? '')),
        ];

        return sha1(implode('|', $parts));
    }

    protected static function normalizeText(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return $value;
    }

    protected static function tableExists(): bool
    {
        try {
            return Schema::hasTable('mpesa_error_codes');
        } catch (Throwable) {
            return false;
        }
    }
}



