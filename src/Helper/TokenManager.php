<?php

declare(strict_types=1);

namespace Nofrixion\WC\Helper;

/**
 * Handles card tokenization.
 */
class TokenManager {

	public static function addToken(
		array $tokenizedCard,
		int $userId,
		string $gatewayId = 'nofrixion_card',
		$setDefault = true
	): ?int {
		Logger::debug('TokenManager::addToken():');
		$token = new \WC_Payment_Token_CC();
		$token->set_token($tokenizedCard['id']);
		$token->set_gateway_id($gatewayId);
		$token->set_card_type($tokenizedCard['cardType']);
		$token->set_last4(substr($tokenizedCard['maskedCardNumber'], -4));
		$token->set_expiry_month($tokenizedCard['expiryMonth']);
		$token->set_expiry_year($tokenizedCard['expiryYear']);
		$token->set_user_id($userId);
		$token->set_default($setDefault);
		if (!$token->save()) {
			Logger::debug('Token: Error on saving payment token.');
			return null;
		} else {
			Logger::debug('Token: Successfully saved payment token. ID: ' . $token->get_id());
			return $token->get_id();
		}
	}

}
