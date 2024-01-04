<?php

class OpenAI {

    private function callAPI($data) {
        $api_key    = get_option('auto_alt_text_api_key');
        $openai_url = 'https://api.openai.com/v1/chat/completions'; // OpenAI API endpoint

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $openai_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key
        ]);

        $response = curl_exec($ch);

        if (!$response) {
            throw new Exception('Error: "' . curl_error($ch) . '" - Code: ' . curl_errno($ch));
        }

        curl_close($ch);

        return json_decode($response, true);
    }

    public function get_image_description($image_url) {
        $data = [
            'model' => 'gpt-4-vision-preview',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => "What’s in this image? Write it as an alt text. Keep it short and descriptive. Don't include words like 'image of' or 'picture of'. Don't write 'alt:' or 'alt text:'."],
                        ['type' => 'image_url', 'image_url' => ['url' => $image_url]]
                    ]
                ]
            ],
            'max_tokens' => 300
        ];

        try {
            $response_data = $this->callAPI($data);
        } catch (Exception $e) {
            // Handle the exception as needed
            return null;
        }

        // Use null coalescing operator to simplify the checks for the existence of keys in the array
        return $response_data['choices'][0]['message']['content'] ?? null;
    }
}