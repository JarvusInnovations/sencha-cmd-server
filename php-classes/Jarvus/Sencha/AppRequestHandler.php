<?php

namespace Jarvus\Sencha;

class AppRequestHandler extends \RequestHandler
{
	public static function handleRequest()
	{
		// get app
		if (!$appName = static::shiftPath()) {
			return static::throwInvalidRequestError('App name required');
		}

		if (!$app = App::get($appName)) {
			return static::throwNotFoundError('App "%s" not found', $appName);
		}


		// redirect to development mode + trailing slash if a deeper path isn't provided
		if (!($nextPath = static::shiftPath()) || ($nextPath == 'development' && static::peekPath() === false)) {
			\Site::redirect(['sencha', 'app', $app, 'development', '']);
		}


		// handle requset
		if ($nextPath == 'development' && !static::peekPath()) {
			return static::handleDevelopmentRequest($app);
		}

		\Debug::dumpVar([
			'$app' => $app,
			'$nextPath' => $nextPath,
			'peekPath' => static::peekPath()
		], true, 'unhandled request');
	}

	public static function handleDevelopmentRequest(App $app)
	{
		return static::respond($app->getFramework()->getName(), [
			'app' => $app,
			'mode' => 'development'
		]);
	}
}