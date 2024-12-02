<?php

namespace CIHub\Bundle\SimpleRESTAdapterBundle\EventListener;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class CorsListener implements EventSubscriberInterface
{
    private LoggerInterface|NullLogger|null $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        if (null === $logger) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            $this->logger->debug('CIHUB: Not a master type request, skipping CORS checks.');

            return;
        }

        $route = $event->getRequest()->get('_route');
        if (!is_string($route) || !str_starts_with($route, 'datahub_rest_endpoints')) {
            $this->logger->debug('CIHUB: Not a datahub request, skipping CORS checks.');

            return;
        }

        $crossOriginHeaders = [
            'Access-Control-Max-Age' => 600,
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Methods' => [
                Request::METHOD_GET,
                Request::METHOD_POST,
                Request::METHOD_OPTIONS,
                Request::METHOD_DELETE,
                Request::METHOD_PUT,
                Request::METHOD_HEAD,
            ],
            'Access-Control-Allow-Headers' => [
                'Origin',
                'Accept',
                'DNT',
                'X-User-Token',
                'Keep-Alive',
                'User-Agent',
                'X-Requested-With',
                'If-Modified-Since',
                'Cache-Control',
                'Content-Type',
                'Access-Control-Request-Method',
                'Access-Control-Request-Headers'
            ],
        ];

        $request = $event->getRequest();
        if ($request->headers->has('Origin')) {
            $crossOriginHeaders['Access-Control-Allow-Origin'] = $request->headers->get('Origin');
        }
        if ($request->headers->has('Access-Control-Allow-Headers')) {
            $crossOriginHeaders['Access-Control-Allow-Headers'][] = $request->headers->get('Access-Control-Allow-Headers');
        }
        if ($request->headers->has('Access-Control-Request-Headers')) {
            $crossOriginHeaders['Access-Control-Allow-Headers'][] = $request->headers->get('Access-Control-Request-Headers');
        }
        if ($request->headers->has('Access-Control-Request-Method')) {
            $crossOriginHeaders['Access-Control-Allow-Methods'][] = $request->headers->get('Access-Control-Request-Method');
        }

        $uniqueAllowHeaders = array_unique($crossOriginHeaders['Access-Control-Allow-Headers']);
        $uniqueAllowMethods = array_unique($crossOriginHeaders['Access-Control-Allow-Methods']);

        $crossOriginHeaders['Access-Control-Allow-Headers'] = implode(',', $uniqueAllowHeaders);
        $crossOriginHeaders['Access-Control-Allow-Methods'] = implode(',', $uniqueAllowMethods);

        $event->getResponse()->headers->set('X-Powered-By', 'https://brandoriented.io');
        $event->getResponse()->headers->add($crossOriginHeaders);
    }
}
