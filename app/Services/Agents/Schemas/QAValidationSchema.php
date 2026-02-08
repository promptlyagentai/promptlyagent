<?php

namespace App\Services\Agents\Schemas;

use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

/**
 * Structured output schema for Research QA Validator agent
 *
 * Ensures reliable JSON parsing of quality assurance validation results.
 * Used to determine if synthesized research meets quality standards and
 * identify specific gaps that need additional research.
 */
class QAValidationSchema extends ObjectSchema
{
    public function __construct()
    {
        parent::__construct(
            name: 'qa_validation',
            description: 'Quality assurance validation result for research synthesis',
            properties: [
                new EnumSchema(
                    name: 'qaStatus',
                    description: 'Overall validation status: pass if all requirements met, fail if gaps exist',
                    options: ['pass', 'fail']
                ),
                new NumberSchema(
                    name: 'overallScore',
                    description: 'Overall quality score from 0-100'
                ),
                new ObjectSchema(
                    name: 'assessment',
                    description: 'Detailed quality assessment scores',
                    properties: [
                        new NumberSchema(name: 'completeness', description: 'Coverage of all query requirements (0-100)'),
                        new NumberSchema(name: 'depth', description: 'Sufficient detail for query complexity (0-100)'),
                        new NumberSchema(name: 'accuracy', description: 'Claims supported by sources (0-100)'),
                        new NumberSchema(name: 'coherence', description: 'Logical flow and synthesis quality (0-100)'),
                    ],
                    requiredFields: ['completeness', 'depth', 'accuracy', 'coherence']
                ),
                new ArraySchema(
                    name: 'requirements',
                    description: 'Analysis of each requirement from the original query',
                    items: new ObjectSchema(
                        name: 'requirement',
                        description: 'Individual requirement assessment',
                        properties: [
                            new StringSchema(name: 'requirement', description: 'Description of what was required'),
                            new BooleanSchema(name: 'addressed', description: 'Whether this requirement was adequately addressed'),
                            new StringSchema(name: 'evidence', description: 'Where/how this was addressed or why it was not'),
                        ],
                        requiredFields: ['requirement', 'addressed', 'evidence']
                    )
                ),
                new ArraySchema(
                    name: 'gaps',
                    description: 'Identified gaps that need additional research',
                    items: new ObjectSchema(
                        name: 'gap',
                        description: 'Specific gap in the research',
                        properties: [
                            new StringSchema(name: 'missing', description: 'What information is missing'),
                            new EnumSchema(name: 'importance', description: 'Priority level of this gap', options: ['critical', 'important', 'nice-to-have']),
                            new StringSchema(name: 'impact', description: 'How this gap affects answer quality'),
                            new StringSchema(name: 'suggestedQuery', description: 'Specific research query to fill this gap'),
                            new StringSchema(name: 'suggestedAgent', description: 'Type of agent that would best address this gap'),
                        ],
                        requiredFields: ['missing', 'importance', 'impact', 'suggestedQuery']
                    )
                ),
                new StringSchema(
                    name: 'recommendations',
                    description: 'Overall feedback and recommended next steps'
                ),
            ],
            requiredFields: ['qaStatus', 'overallScore', 'assessment', 'requirements', 'gaps', 'recommendations']
        );
    }

    /**
     * Convert structured output to QAValidationResult value object
     */
    public static function toQAValidationResult(array $structuredData): \App\Services\Agents\QAValidationResult
    {
        return new \App\Services\Agents\QAValidationResult(
            qaStatus: $structuredData['qaStatus'],
            overallScore: $structuredData['overallScore'],
            assessment: $structuredData['assessment'],
            requirements: $structuredData['requirements'],
            gaps: $structuredData['gaps'],
            recommendations: $structuredData['recommendations']
        );
    }
}
