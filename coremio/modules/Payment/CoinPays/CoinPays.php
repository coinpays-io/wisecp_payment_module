<?php
    class CoinPays extends PaymentGatewayModule
    {
        function __construct()
        {
            $this->name             = __CLASS__;

            parent::__construct();
        }

        public function config_fields()
        {
            return [
                'merchant_id'          => [
                    'name'              => "Merchant ID",
                    'description'       => "You can get this information from the coinpays > settings > integrations.",
                    'type'              => "text",
                    'value'             => $this->config["settings"]["merchant_id"] ?? '',
                    'placeholder'       => "For Example: 123456",
                ],
                'merchant_key'          => [
                    'name'              => "Merchant Key",
                    'description'       => "You can get this information from the coinpays > settings > integrations.",
                    'type'              => "password",
                    'value'             => $this->config["settings"]["merchant_key"] ?? '',
                    'placeholder'       => "For Example: QDKApRON4sMbLl6w",
                ],
                'merchant_salt'          => [
                    'name'              => "Merchant Salt",
                    'description'       => "You can get this information from the coinpays > settings > integrations.",
                    'type'              => "password",
                    'value'             => $this->config["settings"]["merchant_salt"] ?? '',
                    'placeholder'       => "For Example: NW04flaMdjm2R7Kr",
                ]
            ];
        }

        public function area($params=[])
        {
            $merchant_id = $this->config["settings"]["merchant_id"] ?? "none";
            $merchant_key  = $this->config["settings"]["merchant_key"] ?? "none";
            $merchant_salt = $this->config["settings"]["merchant_salt"] ?? "none";
            $email = $this->clientInfo->email;
            $payment_amount	= number_format($params['amount'], 2) * 100;
            $merchant_oid = $this->checkout["id"];
            $user_name = $this->clientInfo->full_name;
            $user_address = $this->clientInfo->address->address;
            $user_phone = $this->clientInfo->gsm_cc;
            $merchant_pending_url = $this->links["successful"];
            $user_basket = base64_encode(json_encode(array(
                array("Sepet ID: ".$this->checkout["id"], number_format($params['amount'], 2), 1),
            )));
            if( isset( $_SERVER["HTTP_CLIENT_IP"] ) ) {
                $ip = $_SERVER["HTTP_CLIENT_IP"];
            } elseif( isset( $_SERVER["HTTP_X_FORWARDED_FOR"] ) ) {
                $ip = $_SERVER["HTTP_X_FORWARDED_FOR"];
            } else {
                $ip = $_SERVER["REMOTE_ADDR"];
            }
            $user_ip= $ip == '::1' || $ip == '127.0.0.1' ? '85.105.186.191' : $ip;
            $test_mode = 0;
            $lang=  "en";
            $url = "https://app.coinpays.io";
            $currency =  $this->currency($params['currency']);
            $hash_str = $merchant_id .$user_ip .$merchant_oid .$email .$payment_amount .$user_basket;
            $coinpays_token=base64_encode(hash_hmac('sha256',$hash_str.$merchant_salt,$merchant_key,true));
            $post_vals=array(
                'merchant_id'=>$merchant_id,
                'user_ip'=>$user_ip,
                'lang'=>$lang,
                'currency' => $currency,
                'merchant_oid'=>$merchant_oid,
                'email'=>$email,
                'payment_amount'=>$payment_amount,
                'coinpays_token'=>$coinpays_token,
                'user_basket'=>$user_basket,
                'user_name'=>$user_name,
                'user_address'=>$user_address,
                'user_phone'=>$user_phone,
                'merchant_pending_url'=>$merchant_pending_url,
                'test_mode' => $test_mode
            );
            
            $ch=curl_init();
            curl_setopt($ch, CURLOPT_URL, $url."/api/get-token");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1) ;
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_vals);
            curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            $result = @curl_exec($ch);
            
            if(curl_errno($ch))
                return "COINPAYS IFRAME API connection error. err:".curl_error($ch);
            
            curl_close($ch);
            
            $result=json_decode($result,1);
            
            if($result['status']=='success'){
                $token=$result['token'];
            }else{
                return "COINPAYS IFRAME API failed. reason:".$result['reason'];
            }
            // Sample return output
            return '
            <script src="'.$url.'/assets/js/iframeResizer.min.js"></script>
            <iframe src="'.$url.'/payment/'.$token.'" id="coinpaysiframe" frameborder="0" scrolling="no" style="width: 100%;"></iframe>
            <script>iFrameResize({},"#coinpaysiframe");</script>
            ';
        }

        public function callback()
        {
            $custom_id  = (int) Filter::init("POST/merchant_oid","numbers");
            if(!$custom_id){
                $this->error = 'Custom id not found.';
                die($this->error);
            }
            $checkout = $this->get_checkout($custom_id);
            if(!$checkout)
            {
                $this->error = 'Checkout ID unknown';
                die($this->error);
            }
            $post = $_POST;
            $merchant_key 	= $this->config["settings"]["merchant_key"] ?? "none";
            $merchant_salt	= $this->config["settings"]["merchant_salt"] ?? "none";
            $hash = base64_encode( hash_hmac('sha256', $post['merchant_oid'].$merchant_salt.$post['status'].$post['total_amount'], $merchant_key, true) );
          
            if( $hash != $post['hash'] )
                die('COINPAYS notification failed: bad hash');
        
            $this->set_checkout($checkout);
            if( $post['status'] == 'success' ) {
                return [
                    'status' => 'successful',
                    'message' => $post,
                    'callback_message' => 'OK',
                    'paid' => [
                        'amount' => $post['total_amount'],
                        'currency' => $post['currency'],
                    ],
                ];
            } else {
                return [
                    'status' => 'error',
                    'message' => $post,
                    'callback_message' => 'OK',
                    'paid' => [
                        'amount' => $post['total_amount'],
                        'currency' => $post['currency'],
                    ],
                ];
            }
        }
    }