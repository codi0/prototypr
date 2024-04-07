<?php

namespace Proto2\Mail;

class Mail {

	protected $fromName = '';
	protected $fromEmail = '';

	protected $eventManager = null;

	public function __construct(array $opts=[], $merge=true) {
		//set opts
		foreach($opts as $k => $v) {
			//property exists?
			if(property_exists($this, $k)) {
				//is array?
				if(!$merge || !is_array($this->$k) || !is_array($v)) {
					$this->$k = $v;
					continue;
				}
				//loop through array
				foreach($v as $a => $b) {
					$this->$k[$a] = $b;
				}
			}
		}
	}

	public function send($to, $subject, $body, array $opts=array()) {
		//set mail array
		$mail = array_merge(array(
			'subject' => trim($subject),
			'body' => trim($body),
			'to_mail' => $to,
			'to_name' => '',
			'from_mail' => $this->fromEmail ?: 'no-reply@' . $_SERVER['HTTP_HOST'],
			'from_name' => $this->fromName,
			'headers' => array(),
			'html' => null,
			'template' => '',
		), $opts);
		//format to mail?
		if(is_string($mail['to_mail'])) {
			$mail['to_mail'] = $mail['to_mail'] ? array_map('trim', explode(',', $mail['to_mail'])) : [];
		}
		//mail event?
		if($this->eventManager) {
			//EVENT: mail.send
			$event = $this->eventManager->dispatch('mail.send', $mail);
			//update mail data
			$mail = array_merge($mail, $event->getParams());
		}
		//valid to addresses?
		foreach($mail['to_mail'] as $t) {
			if(!filter_var($t, FILTER_VALIDATE_EMAIL)) {
				throw new \Exception('Invalid to email address');
			}
		}
		//valid from address?
		if(!filter_var($mail['from_mail'], FILTER_VALIDATE_EMAIL)) {
			throw new \Exception('Invalid from email address');
		}
		//update placeholders
		foreach($mail as $k => $v) {
			if(is_scalar($v)) {
				$mail['subject'] = str_replace('%' . $k . '%', $v, $mail['subject']);
				$mail['body'] = str_replace('%' . $k . '%', $v, $mail['body']);
			}
		}
		//is html?
		if($mail['html'] === null) {
			$mail['html'] = strip_tags($mail['body']) !== $mail['body'];
		}
		//add lines breaks?
		if($mail['html'] && strip_tags($mail['body']) === strip_tags($mail['body'], '<p><br><div><table>')) {
			$mail['body'] = str_replace("\n", "\n<br>\n", $mail['body']);
		}
		//build headers
		$mail['headers'] = $this->buildHeaders($mail);
		//concat to mail
		$mail['to_mail'] = implode(', ', $mail['to_mail']);
		//use safe mode?
		if(ini_get('safe_mode')) {
			return mail($mail['to_mail'], $mail['subject'], $mail['body'], $mail['headers']);
		} else {
			return mail($mail['to_mail'], $mail['subject'], $mail['body'], $mail['headers'], '-f' . $mail['from_mail']);
		}
	}

	protected function buildHeaders(array $mail) {
		//set vars
		$output = '';
		$headers = $mail['headers'];
		//set from header?
		if(!isset($headers['From']) || !$headers['From']) {
			if($mail['from_name']) {
				$headers['From'] = $mail['from_name'] . ' <' . $mail['from_mail'] . '>';
			} else {
				$headers['From'] = $mail['from_mail'];
			}
		}
		//set from header?
		if(!isset($headers['Reply-To']) || !$headers['Reply-To']) {
			$headers['Reply-To'] = $mail['from_mail'];
		}
		//set mime header?
		if(!isset($headers['MIME-Version']) || !$headers['MIME-Version']) {
			if($mail['html']) {
				$headers['MIME-Version'] = '1.0';
			}
		}
		//set content type header?
		if(!isset($headers['Content-Type']) || !$headers['Content-Type']) {
			if($mail['html']) {
				$headers['Content-Type'] = 'text/html; charset=utf-8';
			} else {
				$headers['Content-Type'] = 'text/plain; charset=utf-8';
			}
		}
		//loop through headers
		foreach($headers as $k => $v) {
			$output .= ucfirst($k) . ': ' . $v . "\r\n";
		}
		//return
		return trim($output);
	}

}