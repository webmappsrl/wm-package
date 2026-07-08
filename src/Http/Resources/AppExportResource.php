<?php

namespace Wm\WmPackage\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contratto v1 dell'export apps verso Orchestrator (oc:8242).
 *
 * ⚠️ Questa whitelist È il contratto: aggiungere campi è consentito,
 * rinominare o rimuovere è breaking e richiede /api/v2/export.
 */
class AppExportResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'customer_name' => $this->customer_name,
            'api' => $this->api,
            'ios_store_link' => $this->ios_store_link,
            'android_store_link' => $this->android_store_link,
            'default_language' => $this->default_language,
            'available_languages' => $this->available_languages,
            'welcome' => $this->getTranslations('welcome'),
            'dashboard_show' => (bool) $this->dashboard_show,
            'author_name' => $this->author?->name,
            'author_email' => $this->author?->email,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
