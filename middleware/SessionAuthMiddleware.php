<?php

namespace Grocy\Middleware;

use Grocy\Services\SessionService;
use Psr\Http\Message\ServerRequestInterface as Request;

class SessionAuthMiddleware extends AuthMiddleware
{
	public function authenticate(Request $request)
	{
		$sessionService = SessionService::GetInstance();

		if (!isset($_COOKIE[SessionService::SESSION_COOKIE_NAME]) || !$sessionService->IsValidSession($_COOKIE[SessionService::SESSION_COOKIE_NAME]))
		{
			return null;
		}
		else
		{
			return $sessionService->GetUserBySessionKey($_COOKIE[SessionService::SESSION_COOKIE_NAME]);
		}
	}

	public static function ProcessLogin(array $postParams)
	{
		throw new \Exception('Not implemented');
	}
}
