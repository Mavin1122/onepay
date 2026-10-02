<?php
class BcelApi {
    private $baseUrl = 'https://bcel.la:8667';

    private function crc16($data) {
        $crc = 0xFFFF;
        for ($i = 0; $i < strlen($data); $i++) {
            $crc ^= (ord($data[$i]) << 8);
            for ($j = 0; $j < 8; $j++) {
                if ($crc & 0x8000) {
                    $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFF;
                }
            }
        }
        return $crc;
    }

    private function f($tag, $val) {
        $v = (string)$val;
        return $tag . str_pad(strlen($v), 2, '0', STR_PAD_LEFT) . $v;
    }

    private function formatAmount($amount) {
        $n = (float)$amount;
        if (!$n) return null;
        return (floor($n) == $n) ? (string)$n : number_format($n, 2, '.', '');
    }

    public function buildLaoQr($mcid, $merchantName, $terminalId, $amount = null) {
        $f38 = $this->f('00', 'A005266284662577') .
               $this->f('01', '27710418') .
               $this->f('02', '002') .
               $this->f('03', $mcid);

        $f62 = $this->f('07', str_replace(' ', '_', $terminalId));

        $payload = $this->f('00', '01');
        $payload .= $this->f('01', '11');
        $payload .= $this->f('38', $f38);
        $payload .= $this->f('53', '418');

        if (!empty($amount)) {
            $payload .= $this->f('54', $this->formatAmount($amount));
        }

        $payload .= $this->f('58', 'LA');
        $payload .= $this->f('59', substr($merchantName, 0, 25));
        $payload .= $this->f('60', 'VTE');
        $payload .= $this->f('62', $f62);
        $payload .= '6304';

        $crc = strtoupper(str_pad(dechex($this->crc16($payload)), 4, '0', STR_PAD_LEFT));
        return $payload . $crc;
    }

    private function sendRequest($path, $data, $signature) {
        $url = $this->baseUrl . $path;
        $headers = [
            'Content-Type: application/json',
            'x-device-id: 8ebd08e2754118e2f19748ad301a1f82bce025d7c45be7257c105c924a2ee717',
            'x-device-signature: ' . $signature,
            'User-Agent: Mozilla/5.0 (Linux; Android 12; ASUS_AI2205_A Build/V417IR; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/110.0.5481.154 Mobile Safari/537.36',
            'x-requested-with: com.bcel.onepayshop'
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $httpCode,
            'data' => json_decode($response, true)
        ];
    }

    public function register() {
        $body = [
            'publicKey' => '3059301306072a8648ce3d020106082a8648ce3d03010703420004c85a2b0d748811c9fa1afdfd466d316a907392187ec1e80d51ed42d499ae91933c7c09f43ccc25e15a16192e2f833e64a7f2cb21a9b6cb11cec58dbb0964cd2e',
            'deviceName' => 'Mozilla/5.0 (Linux; Android 12; SM-A546E Build/V417IR; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/110.0.5481.154 Safari/537.36',
            'deviceType' => 'ANDROID',
            'pushId' => 'fRxPe76XT4Cv2JbyKC75WP:APA91bFYzLtL4Chei6LmcmnVxt9EVcjilwLBy87h4Agnx6Nmz5Wdbcy__koqH4WbVgAXjTs0w8TJudhey6rFH0iaT4nKY1BSIAT8gZi5ZjbBbl6IRfQzaZg'
        ];
        return $this->sendRequest('/device/register', $body, "304502207e9ba4bab55157bb79f17148fe79568c0e5f81863820a7d83b867c4f4125bf95022100b13cdd2dd6fc0e11da8b0bd98a6d43982efe6ba1900f43ad752a31c7dc34c816");
    }

    public function activate($code, $token) {
        $body = ['activationcode' => $code, 'devicetoken' => $token];
        return $this->sendRequest('/app/activatebycode', $body, "3045022100eb0be237a20a0eec7e5a33419cc0661970abe7229c17824bbf52175bd48070d6022061e4d405fc154b6cf821cefec12f7b3604e9257eeb9a8da817536400b0084a1a");
    }

    public function getInfo() {
        $body = ['pushId' => 'fRxPe76XT4Cv2JbyKC75WP:APA91bFYzLtL4Chei6LmcmnVxt9EVcjilwLBy87h4Agnx6Nmz5Wdbcy__koqH4WbVgAXjTs0w8TJudhey6rFH0iaT4nKY1BSIAT8gZi5ZjbBbl6IRfQzaZg', 'language' => 'LA', 'version' => 2];
        return $this->sendRequest('/app/loadapp', $body, "30450221008a6862ba760586625a04409f7b78d70d52850ecd2874f758f2423ec16055ade80220385bc37eb0adbf379ba90bec643dbbbc8f70715f661c746391c2063e1c9a33fd");
    }

    public function getHistory($mcid) {
        $body = ['mcid' => $mcid];
        return $this->sendRequest('/app/paymenthistory', $body, "3045022100c35b2a6988fcf5d392eeb5f8160ee002d8da91e0c71738ac5a18a821ddc589c7022078e0756cc55f05421a7776240472a9b58510cef4b6532cacc60d9b940f0a4d38");
    }
}
?>