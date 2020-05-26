<?php


namespace Aeris\GuzzleHttpMock\Expectation;


use Aeris\GuzzleHttpMock\Encoder;
use Aeris\GuzzleHttpMock\Exception\CompoundUnexpectedHttpRequestException;
use Aeris\GuzzleHttpMock\Exception\UnexpectedHttpRequestException;
use Aeris\GuzzleHttpMock\Expect;
use Aeris\GuzzleHttpMock\Exception\FailedRequestExpectationException;
use Aeris\GuzzleHttpMock\Exception\InvalidRequestCountException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use function GuzzleHttp\Psr7\stream_for;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class RequestExpectation {

	/** @var int */
	protected $expectedCallCount = 1;

	/** @var callable[] */
	protected $requestExpectations = [];

	/** @var int */
	protected $actualCallCount = 0;

	/** @var ResponseInterface */
	protected $mockResponse;


	public function __construct(RequestInterface $request = null) {
		$request = $request ?: new Request('GET', '/');

		$this->setExpectedRequest($request);
		$this->mockResponse = $this->createResponse();
	}


	/**
	 * @param RequestInterface $request
	 * @throws FailedRequestExpectationException
	 * @return ResponseInterface
	 */
	public function makeRequest(RequestInterface $request, array $options) {
		$this->validateRequestCanBeMade($request, $options);

		$this->actualCallCount++;
		$response = $this->mockResponse;

		return $response;
	}

	protected function validateRequestCanBeMade(RequestInterface $request, $options) {
		// Check request against expectations
		$errors = array_reduce($this->requestExpectations, function($errors, $expectation) use ($request, $options) {
			try {
				$expectation($request, $options);
			}
			catch (UnexpectedHttpRequestException $err) {
				return array_merge($errors, [$err]);
			}
			return $errors;
		}, []);

		if (count($errors)) {
			throw new CompoundUnexpectedHttpRequestException($errors);
		}

		if ($this->actualCallCount >= $this->expectedCallCount) {
			$actualAttemptedCallCount =  $this->actualCallCount + 1;
			throw new InvalidRequestCountException($actualAttemptedCallCount, $this->expectedCallCount);
		}
	}

	/**
	 * @param RequestInterface $request
	 */
	public function setExpectedRequest($request) {
		parse_str($request->getUri()->getQuery(), $query);
		$this
			->withUrl($request->getUri())
			->withMethod($request->getMethod())
			->withQueryParams($query);

		if ($request->getBody() !== null) {
			$this->withBody($request->getBody());
		}

		if (self::isJson($request)) {
			$this->withJsonContentType();
		}
		return $this;
	}

	/**
	 * @param string|callable $url
	 * @return $this
	 */
	public function withUrl($url) {
		$this->requestExpectations['url'] = new Expect\Predicate(function (RequestInterface $request) use ($url) {
			$expectation = is_callable($url) ? $url : new Expect\Equals(explode('?', $url)[0], 'url');
			$actualUrl = explode('?', (string)$request->getUri())[0];

			return $expectation($actualUrl);
		}, 'URL expectation failed');

		return $this;
	}

	public function withMethod($method) {
		$this->requestExpectations['method'] = new Expect\Predicate(function (RequestInterface $request) use ($method) {
			$expectation = is_callable($method) ? $method : new Expect\Equals($method, 'http method');

			return $expectation($request->getMethod());
		}, 'HTTP method expectation failed');

		return $this;
	}


		/**
	 * @param array|callable $queryParams
   * @param array $exludes params excludes
	 * @return $this
	 */
	// public function withQueryParams($queryParams, $excludes = []) {
		public function withQueryParams($queryParams, $excludes = []) {
			echo "------";
			var_dump($excludes);
			$this->requestExpectations['query'] = new Expect\Predicate(function (RequestInterface $request) use ($queryParams, $excludes) {
				// $expectation  = $queryParams;
				if (is_callable($queryParams)) {
					$expectation  = $queryParams;
				} else {
			
					// if($excludes) $excludes = array();
					array_walk($excludes, function($param) use(&$queryParams) {
  
						if (in_array($param, array_keys($queryParams))) {
							unset($queryParams[$param]);
						}
					  });
					  			
					$expectation = new Expect\ArrayEquals($queryParams, 'query params');
				}
	
				// The client library of guzzle automatically appends the query params to the uri before
				// invoking the middleware stack
				parse_str($request->getUri()->getQuery(), $query);
				return $expectation($query);
			}, 'query params expectation failed');
	
			return $this;
		}
	

// 	/**
// 	 * @param array|callable $queryParams
//    * @param array $exludes params excludes
// 	 * @return $this
// 	 */
	
// 	public function withQueryParams($queryParams) {
// 		$this->requestExpectations['query'] = new Expect\Predicate(function (RequestInterface $request) use ($queryParams) {
// 			$expectation  = is_callable($queryParams)? $queryParams:new Expect\ArrayEquals($queryParams, 'query params');
// 			// The client library of guzzle automatically appends the query params to the uri before
// 				// invoking the middleware stack
// 				parse_str($request->getUri()->getQuery(), $query);
// 				return $expectation($query);
// 		}, 'query params expectation failed');

// 		return $this;
// 	}

	/**
	 * @param string $queryString
	 * @return $this
	 */
	public function withQueryString($queryString) {
		$this->requestExpectations['query'] = new Expect\Predicate(function (RequestInterface $request) use ($queryString) {
			$expectation = is_callable($queryString) ? $queryString : new Expect\Equals($queryString, 'query string');
			return $expectation($request->getUri()->getQuery());
		}, 'query string expectation failed');

		return $this;
	}

	/**
	 * @param $contentType
	 * @return $this
	 */
	public function withContentType($contentType) {
		$this->requestExpectations['contentType'] = new Expect\Predicate(function (RequestInterface $request) use ($contentType) {
			$expectation = is_callable($contentType) ? $contentType : new Expect\Matches("#$contentType#", 'content type');

			return $expectation($request->getHeaderLine('Content-Type'));
		}, 'content type expectation failed');

		return $this;
	}

	public function withJsonContentType() {
		return $this->withContentType('application/json');
	}

	/**
	 * @param callable|StreamInterface $stream
	 * @return $this
	 */
	public function withBody($stream) {
		$this->requestExpectations['body'] = new Expect\Predicate(function (RequestInterface $request) use ($stream) {
			$expectation = is_callable($stream) ? $stream : new Expect\Equals((string)$stream, 'body content');

			return $expectation((string)$request->getBody());
		}, 'body expectation failed');

		return $this;
	}

	/**
	 * @param callable|StreamInterface $stream
	 * @return $this
	 */
	public function withBodyParams($params) {
		$this->requestExpectations['body'] = new Expect\Predicate(function (RequestInterface $request) use ($params) {
			$expectation = is_callable($params) ? $params : new Expect\ArrayEquals($params, 'body params');

			$actualBodyParams = self::parseRequestBody($request);
			return $expectation($actualBodyParams);
		}, 'body params expectation failed');

		return $this;
	}

	private static function parseRequestBody(RequestInterface $request) {
		if (!$request->getBody()) {
			return [];
		}

		$body = $request->getBody();

		if ($body instanceof StreamInterface) {
			try {
				$body = (string)$body;
			} catch (\Exception $e) {
				throw new FailedRequestExpectationException('the body stream resource was not readable', false, true);
			}
		} else {
			throw new FailedRequestExpectationException('body is not a stream resource', false, true);
		}


		if ($request->getHeaderLine('Content-Type') && $request->getHeaderLine('Content-Type') === 'application/x-www-form-urlencoded') {
			parse_str($body, $result);
			return $result;
		}

		if ($request->getHeaderLine('Content-Type') && $request->getHeaderLine('Content-Type') === 'application/json') {
			try {
				$data = \GuzzleHttp\json_decode((string)$body, true);
			} catch (\Exception $ex) {
				throw new FailedRequestExpectationException('body is invalid json: ' . json_last_error_msg(), false, true);
			}

			return $data;
		}

		throw new FailedRequestExpectationException('body is a raw stream', false, true);
	}

	public function withJsonBodyParams(array $params) {
		$this->withJsonContentType();

		return $this->withBodyParams($params);
	}

	public function once() {
		$this->expectedCallCount = 1;

		return $this;
	}

	public function times($expectedCallCount) {
		$this->expectedCallCount = $expectedCallCount;

		return $this;
	}

	public function zeroOrMoreTimes() {
		$this->expectedCallCount = INF;

		return $this;
	}

	public function andRespondWith(ResponseInterface $response) {
		$this->mockResponse = $response;

		return $this;
	}

	public function andRespondWithContent(array $data, $statusCode = null, $encoder = null) {
		if (!is_null($statusCode)) {
			$this->andRespondWithCode($statusCode);
		}

		$stream = $this->createStream($data, $encoder);

		$this->mockResponse = $this->mockResponse->withBody($stream);

		return $this;
	}

	public function andRespondWithJson(array $data, $statusCode = null) {
		return $this->andRespondWithContent($data, $statusCode, Encoder::Json());
	}

	public function andRespondWithCode($code) {
		$this->mockResponse = $this->mockResponse->withStatus($code);

		return $this;
	}

	private function createResponse($code = 200) {
		return new Response($code);
	}

	protected function createStream(array $data, $encoder = null) {
		if (is_null($encoder)) {
			$encoder = Encoder::HttpQuery();
		}

		return stream_for($encoder($data));
	}

	public function verify() {
		if ($this->expectedCallCount === INF) {
			return;
		}

		if ($this->actualCallCount !== $this->expectedCallCount) {
			throw new InvalidRequestCountException($this->actualCallCount, $this->expectedCallCount);
		}
	}

	public static function isJson(RequestInterface $request) {
		return !!preg_match('#^application/json#', $request->getHeaderLine('Content-Type'));
	}
}
