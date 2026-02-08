<?php

namespace App\Http\Requests\Api\V1\Knowledge;

class ListKnowledgeRequest extends KnowledgeApiRequest
{
    protected function requiredAbility(): string
    {
        return 'knowledge:view';
    }

    public function rules(): array
    {
        return $this->filterRules();
    }
}
