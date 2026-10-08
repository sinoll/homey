<?php

declare(strict_types=1);

namespace App\Api\Shares;

use App\Api\Shared\ResponseFactory;
use App\Service\ShareService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

final readonly class PublicDetailsAction
{
    public function __construct(
        private ShareService $shares,
        private ResponseFactory $responses,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        #[RouteArgument]
        string $token,
    ): ResponseInterface {
        $share = $this->shares->getPublicShare($token);
        if ($share === null) {
            return $this->responses->notFound('Share link not found or expired.');
        }
        $details = $this->shares->publicDetails($share);
        if ($details === null) {
            return $this->responses->notFound('Shared item not found.');
        }

        return $this->responses->success($details);
    }
}
