<?php declare(strict_types=1);

namespace League\Route\Strategy;

use League\Route\{ContainerAwareInterface, ContainerAwareTrait};
use League\Route\Http\Exception as HttpException;
use League\Route\Http\Exception\{MethodNotAllowedException, NotFoundException};
use League\Route\Route;
use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use Throwable;

class JsonStrategy extends AbstractStrategy implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    /**
     * @var ResponseFactoryInterface
     */
    protected $responseFactory;

    /**
     * Construct.
     *
     * @param ResponseFactoryInterface $responseFactory
     */
    public function __construct(ResponseFactoryInterface $responseFactory)
    {
        $this->responseFactory = $responseFactory;

        $this->addDefaultResponseHeader('content-type', 'application/json');
    }

    /**
     * {@inheritdoc}
     */
    public function invokeRouteCallable(Route $route, ServerRequestInterface $request): ResponseInterface
    {
        $controller = $route->getCallable($this->getContainer());
        $response = $controller($request, $route->getVars());

        if ($this->isJsonEncodable($response)) {
            $body     = json_encode($response);
            $response = $this->responseFactory->createResponse();
            $response->getBody()->write($body);
        }

        $response = $this->applyDefaultResponseHeaders($response);

        return $response;
    }

    /**
     * Check if the response can be converted to JSON
     *
     * Arrays can always be converted, objects can be converted if they're not a response already
     *
     * @param mixed $response
     *
     * @return bool
     */
    protected function isJsonEncodable($response): bool
    {
        if ($response instanceof ResponseInterface) {
            return false;
        }

        return (is_array($response) || is_object($response));
    }

    /**
     * {@inheritdoc}
     */
    public function getNotFoundDecorator(NotFoundException $exception): MiddlewareInterface
    {
        return $this->buildJsonResponseMiddleware($exception);
    }

    /**
     * {@inheritdoc}
     */
    public function getMethodNotAllowedDecorator(MethodNotAllowedException $exception): MiddlewareInterface
    {
        return $this->buildJsonResponseMiddleware($exception);
    }

    /**
     * Return a middleware the creates a JSON response from an HTTP exception
     *
     * @param HttpException $exception
     *
     * @return MiddlewareInterface
     */
    protected function buildJsonResponseMiddleware(HttpException $exception): MiddlewareInterface
    {
        return new class($this->responseFactory->createResponse(), $exception) implements MiddlewareInterface
        {
            protected $response;
            protected $exception;

            public function __construct(ResponseInterface $response, HttpException $exception)
            {
                $this->response  = $response;
                $this->exception = $exception;
            }

            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $requestHandler
            ): ResponseInterface {
                return $this->exception->buildJsonResponse($this->response);
            }
        };
    }

    /**
     * {@inheritdoc}
     */
    public function getExceptionHandler(): MiddlewareInterface
    {
        return $this->getThrowableHandler();
    }

    /**
     * {@inheritdoc}
     */
    public function getThrowableHandler(): MiddlewareInterface
    {
        return new class($this->responseFactory->createResponse()) implements MiddlewareInterface
        {
            protected $response;

            public function __construct(ResponseInterface $response)
            {
                $this->response = $response;
            }

            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $requestHandler
            ): ResponseInterface {
                try {
                    return $requestHandler->handle($request);
                } catch (Throwable $exception) {
                    $response = $this->response;
                    $code = 500;

                    if ($exception instanceof HttpException) {
                        return $exception->buildJsonResponse($response);
                    }

                    if ($exception->getCode() > 399 && $exception->getCode() < 600) {
                        $code = $exception->getCode();
                    }

                    $response->getBody()->write(json_encode([
                        'status_code'   => $code,
                        'reason_phrase' => $exception->getMessage()
                    ]));

                    $response = $response->withAddedHeader('content-type', 'application/json');

                    return $response->withStatus($code, strtok($exception->getMessage(), "\n"));
                }
            }
        };
    }
}
