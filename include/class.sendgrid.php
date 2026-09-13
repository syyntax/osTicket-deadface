<?php
/*********************************************************************
    class.sendgrid.php

    Delivers an osTicket\Mail\Message through SendGrid's v3 Web API, for
    hosts (e.g. DigitalOcean) where outbound SMTP is blocked.
**********************************************************************/
namespace osTicket\Mail;

class SendGrid {
    const ENDPOINT = 'https://api.sendgrid.com/v3/mail/send';

    // Set by SendGrid itself or rejected when passed as custom headers.
    static $reserved = array('from', 'to', 'cc', 'bcc', 'subject', 'reply-to',
        'sender', 'return-path', 'date', 'mime-version', 'content-type',
        'content-transfer-encoding');

    static function send(Message $message, $apiKey, $sandbox=false) {
        $json = json_encode(self::buildPayload($message, $sandbox),
            JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false)
            throw new \Exception('Unable to encode SendGrid payload: '.json_last_error_msg());

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer '.$apiKey,
                'Content-Type: application/json',
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ));
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false)
            throw new \Exception('SendGrid request failed: '.$error);

        if ($status < 200 || $status >= 300) {
            $details = json_decode($response, true);
            $messages = array();
            foreach ((array) ($details['errors'] ?? array()) as $e)
                $messages[] = trim((!empty($e['field']) ? $e['field'].': ' : '')
                    .($e['message'] ?? ''));
            throw new \Exception(sprintf('SendGrid API returned HTTP %d: %s', $status,
                $messages ? implode('; ', $messages) : substr($response, 0, 500)));
        }
        return true;
    }

    static function buildPayload(Message $message, $sandbox=false) {
        $headers = $message->getHeaders();

        // SendGrid rejects the same address appearing in more than one list.
        $seen = array();
        $addresses = function($name) use ($message, $headers, &$seen) {
            $out = array();
            if (!$headers->has($name))
                return $out;
            $list = call_user_func(array($message, 'get'.ucfirst($name)));
            foreach ($list as $address) {
                $email = $address->getEmail();
                if (!$email || isset($seen[strtolower($email)]))
                    continue;
                $seen[strtolower($email)] = true;
                $entry = array('email' => $email);
                if ($address->getName())
                    $entry['name'] = $address->getName();
                $out[] = $entry;
            }
            return $out;
        };

        $from = null;
        foreach ($message->getFrom() as $address) {
            $from = array('email' => $address->getEmail());
            if ($address->getName())
                $from['name'] = $address->getName();
            break;
        }
        if (!$from)
            throw new \Exception('Message has no From address');

        $to = $addresses('to');
        $cc = $addresses('cc');
        $bcc = $addresses('bcc');
        // SendGrid requires a To; osTicket can send Cc/Bcc-only messages.
        if (!$to)
            $to = $cc ? array(array_shift($cc)) : array($from);

        $personalization = array('to' => $to);
        if ($cc)
            $personalization['cc'] = $cc;
        if ($bcc)
            $personalization['bcc'] = $bcc;

        $payload = array(
            'personalizations' => array($personalization),
            'from' => $from,
            'subject' => (string) $message->getSubject() ?: '(no subject)',
        );

        if ($headers->has('reply-to')) {
            foreach ($message->getReplyTo() as $address) {
                $payload['reply_to'] = array('email' => $address->getEmail());
                if ($address->getName())
                    $payload['reply_to']['name'] = $address->getName();
                break;
            }
        }

        $bodies = array();
        foreach ($message->getMimeMessageContent()->getParts() as $part) {
            $type = strtolower((string) $part->type);
            if ($type == 'text/plain' || $type == 'text/html')
                $bodies[$type] = array('type' => $type, 'value' => $part->getRawContent());
        }
        // text/plain must precede text/html.
        $payload['content'] = array_values(array_filter(array(
            $bodies['text/plain'] ?? null, $bodies['text/html'] ?? null)));
        if (!$payload['content'])
            throw new \Exception('Message has no body');

        $attachments = array();
        foreach ($message->getMimeMessageParts()->getParts() as $part) {
            $attachment = array(
                'content' => base64_encode($part->getRawContent()),
                'type' => $part->type ?: 'application/octet-stream',
                'filename' => $part->filename ?: 'attachment',
                'disposition' => $part->disposition == 'inline' ? 'inline' : 'attachment',
            );
            if ($attachment['disposition'] == 'inline' && $part->id)
                $attachment['content_id'] = $part->id;
            $attachments[] = $attachment;
        }
        if ($attachments)
            $payload['attachments'] = $attachments;

        // Keeps Message-ID / In-Reply-To / References so replies thread.
        $custom = array();
        foreach ($headers as $header) {
            $name = $header->getFieldName();
            if (in_array(strtolower($name), self::$reserved))
                continue;
            $value = trim(preg_replace('/\r?\n\s*/', ' ', $header->getFieldValue()));
            if ($value !== '')
                $custom[$name] = $value;
        }
        if ($custom)
            $payload['headers'] = $custom;

        // Ticket links carry access tokens; don't route them through
        // SendGrid's click-tracking redirector.
        $payload['tracking_settings'] = array(
            'click_tracking' => array('enable' => false, 'enable_text' => false),
            'open_tracking' => array('enable' => false),
        );

        if ($sandbox)
            $payload['mail_settings'] = array('sandbox_mode' => array('enable' => true));

        return $payload;
    }
}
