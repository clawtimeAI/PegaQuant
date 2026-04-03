<?php

namespace plugin\webman\gateway;

use support\Db;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class PairInfoSync
{
    // 邮件配置（根据实际需求修改）
    private static $mailConfig = [
        'smtpHost' => 'smtp.qq.com', // SMTP 服务器地址
        'smtpPort' => 587,                // SMTP 端口
        'smtpUser' => '362395084@qq.com', // SMTP 用户名
        'smtpPass' => 'bnvpjdylaqbvcbeg',    // SMTP 密码
        'fromEmail' => '362395084@qq.com', // 发件人邮箱
        'fromName' => 'New Pair', // 发件人名称
        'toEmail' => 'vbboy2015@gmail.com', // 收件人邮箱
    ];

    /**
     * Fetches and updates pair info from Binance Futures API into ApairInfo table.
     * Sends email notification for new trading pairs.
     * @return void
     */
    public static function syncPairInfo()
    {
        $startTime = time();
        $apiUrl = "https://fapi.binance.com/fapi/v1/exchangeInfo";

        try {
            // Fetch data from API
            $response = self::getData($apiUrl);
            $data = json_decode($response, true);

            if (!isset($data['symbols']) || empty($data['symbols'])) {
                echo "No symbols found in API response.\n";
                return;
            }

            echo "Processing " . count($data['symbols']) . " symbols.\n";

            // Prepare data for batch insert/update
            $values = [];
            $newPairs = []; // Track new trading pairs for email notification
            foreach ($data['symbols'] as $symbol) {
                $pricePrecision = $symbol['pricePrecision'] ?? 8;
                $quantityPrecision = $symbol['quantityPrecision'] ?? 8;
                $baseAssetPrecision = $symbol['baseAssetPrecision'] ?? 8;
                $quotePrecision = $symbol['quotePrecision'] ?? 8;
                $onboardDate = date('Y-m-d H:i:s', $symbol['onboardDate'] / 1000);
                $status = $symbol['status'] ?? 'UNKNOWN';
                $currentTime = date('Y-m-d H:i:s');

                // Check if symbol already exists in database
                $exists = Db::table('ApairInfo')->where('symbol', $symbol['symbol'])->exists();
                if (!$exists) {
                    // Track new pair for email notification
                    $newPairs[] = [
                        'symbol' => $symbol['symbol'],
                        'insertTime' => $currentTime,
                    ];
                }

                $values[] = [
                    'symbol' => $symbol['symbol'],
                    'baseAsset' => $symbol['baseAsset'],
                    'quoteAsset' => $symbol['quoteAsset'],
                    'pricePrecision' => $pricePrecision,
                    'quantityPrecision' => $quantityPrecision,
                    'baseAssetPrecision' => $baseAssetPrecision,
                    'quotePrecision' => $quotePrecision,
                    'onboardDate' => $onboardDate,
                    'updateDate' => $currentTime,
                    'workid' => null,
                    'status' => $status,
                    'isCreate' => false,
                    'isOpen' => $status === 'TRADING',
                ];
            }

            // Batch insert or update
            self::batchInsertOrUpdate($values);

            // Send email for new pairs if any
            if (!empty($newPairs)) {
                self::sendNewPairEmail($newPairs);
            }

        } catch (\Exception $e) {
            echo "Error fetching or processing data: {$e->getMessage()}\n";
        }

        // Calculate and log execution time
        $useTime = time() - $startTime;
        echo "Used {$useTime}s\n";
    }

    /**
     * Batch inserts or updates data into ApairInfo table.
     * @param array $dataArray Array of data to insert/update
     * @return void
     */
    private static function batchInsertOrUpdate(array $dataArray)
    {
        if (empty($dataArray)) {
            return;
        }

        $updateColumns = [
            'baseAsset', 'quoteAsset', 'pricePrecision', 'quantityPrecision',
            'baseAssetPrecision', 'quotePrecision', 'onboardDate', 'updateDate',
            'status', 'isCreate', 'isOpen'
        ];

        Db::table('ApairInfo')->upsert($dataArray, ['symbol'], $updateColumns);
        echo "Batch inserted/updated " . count($dataArray) . " records\n";
    }

    /**
     * Sends email notification for new trading pairs.
     * @param array $newPairs Array of new pairs with symbol and insertTime
     * @return void
     */
    private static function sendNewPairEmail(array $newPairs)
    {
        $mailer = new PHPMailer(true);

        try {
            // Server settings
            $mailer->isSMTP();
            $mailer->Host = self::$mailConfig['smtpHost'];
            $mailer->SMTPAuth = true;
            $mailer->Username = self::$mailConfig['smtpUser'];
            $mailer->Password = self::$mailConfig['smtpPass'];
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mailer->Port = self::$mailConfig['smtpPort'];

            // Recipients
            $mailer->setFrom(self::$mailConfig['fromEmail'], self::$mailConfig['fromName']);
            $mailer->addAddress(self::$mailConfig['toEmail']);

            // Content
            $mailer->isHTML(true);
            $mailer->Subject = 'Binance Futures: New Trading Pair Notification';
            $body = '<h2>New Trading Pairs Added</h2><ul>';
            foreach ($newPairs as $pair) {
                $body .= "<li>New Add {$pair['symbol']} {$pair['insertTime']}</li>";
            }
            $body .= '</ul>';
            $mailer->Body = $body;
            $mailer->AltBody = strip_tags($body); // Plain text for non-HTML clients

            // Send email
            $mailer->send();
            echo "Email sent for " . count($newPairs) . " new trading pairs.\n";
        } catch (Exception $e) {
            echo "Failed to send email: {$mailer->ErrorInfo}\n";
        }
    }

    /**
     * Fetches data from the given URL using cURL.
     * @param string $url API endpoint
     * @return string Raw API response
     * @throws \Exception If cURL request fails
     */
    private static function getData(string $url): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $output = curl_exec($ch);

        if ($output === false) {
            $error = curl_error($ch);
            $ch = null;
            throw new \Exception("cURL error: $error");
        }

        $ch = null;
        return $output;
    }
}
