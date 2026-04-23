<?php

declare(strict_types=1);

namespace Wm\WmPackage\Nova\Cards\ApiLinksCard;

use Laravel\Nova\Card;

class ApiLinksCard extends Card
{
    public $component = 'api-links-card';

    public $width = '1/3';

    public $onlyOnDetail = true;

    /** @param array<int, array{label: string, url: string}> $links */
    public function __construct(private array $links) {}

    public function addLink(string $label, string $url): static
    {
        $this->links[] = ['label' => $label, 'url' => $url];

        return $this;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_merge(parent::jsonSerialize(), [
            'links' => $this->links,
        ]);
    }
}
