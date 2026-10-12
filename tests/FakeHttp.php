<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/** Handler HTTP simulé : une réponse par hôte, historique des appels. */
class FakeHttp
{
	/** @var array<string, Response|callable|string> hôte => réponse, callable(RequestInterface): Response, ou 'timeout' */
	public array $Routes = [];
	/** @var string[] hôtes appelés, dans l'ordre */
	public array $Calls = [];

	public function Handler(): callable
	{
		return function (RequestInterface $request, array $options)
		{
			$host = $request->getUri()->getHost();
			$this->Calls[] = $host;
			$route = $this->Routes[$host] ?? null;

			if ($route === 'timeout')
			{
				return Create::rejectionFor(new ConnectException('cURL error 28: Operation timed out for ' . $request->getUri(), $request));
			}
			if ($route === null)
			{
				return Create::promiseFor(new Response(404, [], '{"status":0}'));
			}
			return Create::promiseFor($route instanceof Response ? $route : $route($request, $options));
		};
	}
}
