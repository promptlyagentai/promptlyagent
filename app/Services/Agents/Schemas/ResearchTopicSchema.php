<?php

namespace App\Services\Agents\Schemas;

use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

class ResearchTopicSchema extends ObjectSchema
{
    public function __construct()
    {
        parent::__construct(
            name: 'research_topics',
            description: 'AI-generated research topic suggestions personalized for the user',
            properties: [
                new ArraySchema(
                    name: 'topics',
                    description: 'Array of research topic suggestions (typically 12 topics for variety)',
                    items: new ObjectSchema(
                        name: 'topic',
                        description: 'A single research topic with metadata',
                        properties: [
                            new StringSchema(
                                name: 'title',
                                description: 'Short, catchy title for the research topic (3-5 words)'
                            ),
                            new StringSchema(
                                name: 'description',
                                description: 'Brief one-sentence description of what the research covers (max 100 characters)'
                            ),
                            new StringSchema(
                                name: 'query',
                                description: 'Detailed research query that will be used to populate the search input (10-30 words, actionable and specific)'
                            ),
                            new EnumSchema(
                                name: 'icon_type',
                                description: 'Icon category that best represents this topic',
                                options: ['ai', 'code', 'database', 'security', 'cloud', 'web', 'mobile', 'data']
                            ),
                            new EnumSchema(
                                name: 'color_theme',
                                description: 'Tailwind color scheme for visual styling',
                                options: ['accent', 'emerald', 'purple', 'orange', 'blue', 'indigo', 'pink', 'teal']
                            ),
                        ],
                        requiredFields: ['title', 'description', 'query', 'icon_type', 'color_theme']
                    )
                ),
            ],
            requiredFields: ['topics']
        );
    }
}
