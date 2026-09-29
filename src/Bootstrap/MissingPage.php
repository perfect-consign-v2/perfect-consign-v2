<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectApp\Session\Session;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Tenant\TenantRecord;
use Psr\Log\LoggerInterface;

final class MissingPage
{
    public function __construct(
        private readonly Session $session,
        private readonly LoggerInterface $logger,
        private readonly View $view,
        private readonly Request $request,
        private readonly TenantLookup $tenants,
    ) {
    }

    public function render(string $reason, string $csrf): never
    {
        http_response_code(404);
        $homeTenant = $this->sessionTenant();
        $pathTenant = $this->pathTenant();
        $displayName = '';
        $home = '';
        $signIn = '';
        if ($homeTenant !== null) {
            $displayName = $homeTenant->displayName;
            $home = $homeTenant->slug;
        } elseif ($pathTenant !== null) {
            $displayName = $pathTenant->displayName;
            $signIn = $pathTenant->slug;
        }
        $this->recordMiss($reason, $reason === MallApp::MISS_ROUTE && $pathTenant === null);
        $this->view->render('Shared/Web/layout', [
            'title' => 'Not found',
            'displayName' => $displayName,
            'slug' => $home,
            'nav' => $home !== '',
            'csrf' => $home !== '' ? $csrf : '',
            'error' => null,
            'notice' => null,
            'warning' => null,
            'path' => $this->request->path,
            'body' => 'Shared/Web/not-found',
            'robots' => 'noindex',
            'showSearch' => false,
            'homeSlug' => $home,
            'signInSlug' => $signIn,
            'back' => $this->backPath(),
        ]);
        throw new ResponseSent('not found');
    }

    private function recordMiss(string $reason, bool $debug): void
    {
        $context = [
            'method' => $this->request->method,
            'path' => $this->request->path,
            'reason' => $reason,
        ];
        if ($debug) {
            $this->logger->debug('page not found', $context);

            return;
        }
        $this->logger->info('page not found', $context);
    }

    private function sessionTenant(): ?TenantRecord
    {
        $slug = $this->session->get('owner_slug');
        if (!is_string($slug) || $slug === '') {
            return null;
        }

        return $this->tenants->find($slug);
    }

    private function pathTenant(): ?TenantRecord
    {
        if (preg_match('#^/([a-z][a-z0-9-]{0,62})(?:/|$)#', $this->request->path, $match) !== 1) {
            return null;
        }

        return $this->tenants->find($match[1]);
    }

    private function backPath(): string
    {
        $referer = $this->request->referer;
        if ($referer === '' || $referer === $this->request->path) {
            return '';
        }

        return $referer;
    }
}
