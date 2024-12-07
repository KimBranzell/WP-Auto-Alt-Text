<?php

class OpenAI {

    /**
     * Calls the OpenAI API with the provided data and returns the response.
     *
     * This private function is responsible for making the API request to OpenAI and handling the response.
     * It checks if the API key is configured, sets up the cURL request, and processes the response.
     * If the API request is successful, it returns the decoded response data. If an error occurs, it throws an exception.
     *
     * @param array $data The data to be sent in the API request.
     * @return array The decoded response data from the OpenAI API.
     * @throws Exception If the API key is not configured or if there is an error during the API request.
     */
    private function callAPI($data) {
        $api_key    	= get_option('auto_alt_text_api_key');

        if (empty($api_key)) {
            throw new Exception('OpenAI API key is not configured');
        }

		$openai_url 	= 'https://api.openai.com/v1/chat/completions'; // OpenAI API endpoint

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
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (!$response) {
            throw new Exception('cURL Error: ' . curl_error($ch));
        }

        curl_close($ch);

        $response_data = json_decode($response, true);

        if ($http_code !== 200) {
            throw new Exception(
                isset($response_data['error']['message'])
                ? $response_data['error']['message']
                : 'Unknown API error'
            );
        }

        return json_decode($response, true);
    }

    /**
     * Generates a concise, SEO-friendly alt text description for the provided image URL.
     *
     * This method uses the OpenAI API to analyze the image and generate a descriptive alt text
     * that adheres to accessibility and SEO best practices. The generated alt text is tailored
     * to the user's language preference and image content.
     *
     * @param string $image_url The URL of the image to generate alt text for.
     * @return string|null The generated alt text description, or null if an error occurred.
     */
    public function get_image_description($image_url) {
        try {
            $language = get_option('language', 'en'); // Default to English if not set
            $instruction = "You are an expert in accessibility and SEO optimization, tasked with generating alt text for images. Analyze the image provided and generate a concise, descriptive alt text tailored to the following requirements:

                Keep it short (1-2 sentences) and descriptive, focusing on the essential elements in the image. Don't overthink it. Consider key elements of why you chose this image, instead of describing every little detail.
                Do not include phrases such as \"image of\" or \"picture of.\"
                Avoid adding any prefixes like \"alt:\" or \"alt text:\".
                Write the text in {$language} language, ensuring it adheres to cultural and linguistic conventions of {$language}.
                For ambiguous images, assume communicative intent and describe them neutrally (e.g., \"A coffee cup on a wooden table\").
                For abstract images with no clear focal point, focus on general characteristics (e.g., \"Abstract patterns with swirling blue and green lines\").
                Incorporate keywords relevant to the image content to enhance SEO optimization. Use metadata as the primary source for keywords, ensuring alignment with the image's primary subject and accessibility goals.
                If {$language} is unsupported, default to English.
                Use plain and easy-to-understand language to prioritize accessibility for a broad audience.

                Output:
                A single, SEO-friendly alt text description for the image in {$language}";

            $data = [
                'model' => 'gpt-4o',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $instruction],
                            ['type' => 'image_url', 'image_url' => ['url' => $image_url]]
                        ]
                    ]
                ],
                'max_tokens' => 300
            ];

            $response_data = $this->callAPI($data);
            return $response_data['choices'][0]['message']['content'] ?? null;

        } catch (Exception $e) {
            error_log('Auto Alt Text Error: ' . $e->getMessage());
            return null;
        }
    }
}
