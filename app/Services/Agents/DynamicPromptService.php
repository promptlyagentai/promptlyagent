<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Models\ChatInteraction;
use Illuminate\Support\Str;

/**
 * Dynamic Prompt Service - Runtime Context Injection.
 *
 * Generates dynamic tool instructions and contextual information that gets injected
 * into agent system prompts at execution time. Enables agents to adapt their behavior
 * based on available tools, MCP servers, and conversation context.
 *
 * Key Responsibilities:
 * - Tool instruction generation from enabled tools and MCP servers
 * - Contextual information injection (attachments, URLs, citations)
 * - MCP server tool discovery and documentation
 * - Tool override handling for InputTrigger scenarios
 *
 * Injection Strategy:
 * 1. Base system prompt (from Agent model)
 * 2. Dynamic tool instructions (this service)
 * 3. Contextual information (this service)
 * 4. Knowledge context (AgentKnowledgeService)
 * 5. Anti-hallucination protocol (AgentService)
 *
 * MCP Integration:
 * - Dynamically loads MCP server tools via Relay
 * - Injects tool descriptions into system prompt
 * - Handles server connection failures gracefully
 * - Falls back to agent's enabled tools on MCP failure
 */
class DynamicPromptService
{
    protected ToolRegistry $toolRegistry;

    public function __construct(?ToolRegistry $toolRegistry = null)
    {
        $this->toolRegistry = $toolRegistry ?? app(ToolRegistry::class);
    }

    /**
     * Generate dynamic tool instructions based on available tools
     */
    public function generateToolInstructions(Agent $agent, ?array $toolOverrides = null): string
    {
        if ($toolOverrides) {
            $enabledToolNames = $toolOverrides['enabled_tools'] ?? [];
            $enabledServerNames = $toolOverrides['enabled_servers'] ?? [];

            $enabledTools = $agent->tools()
                ->whereIn('tool_name', $enabledToolNames)
                ->byPriority()
                ->get();
            foreach ($enabledServerNames as $serverName) {
                try {
                    $relayServers = config('relay.servers', []);
                    if (isset($relayServers[$serverName]) && $serverName !== 'local') {
                        $serverTools = \Prism\Relay\Facades\Relay::tools($serverName);

                        foreach ($serverTools as $serverTool) {
                            if (is_object($serverTool) && method_exists($serverTool, 'name')) {
                                $toolDescription = method_exists($serverTool, 'description')
                                    ? $serverTool->description()
                                    : 'MCP server tool for specialized operations';

                                $mcpTool = (object) [
                                    'tool_name' => $serverTool->name(),
                                    'priority_level' => 'standard',
                                    'min_results_threshold' => null,
                                    'execution_strategy' => 'always',
                                    'mcp_description' => $toolDescription,
                                    'mcp_server' => $serverName,
                                ];
                                $enabledTools->push($mcpTool);
                            }
                        }
                    }
                } catch (\Exception $e) {
                    \Log::warning("Failed to load MCP tools from server {$serverName} for instructions", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } else {
            $enabledTools = $agent->enabledTools()->byPriority()->get();
        }

        if ($enabledTools->isEmpty()) {
            return '';
        }

        $instructions = "## Available Tools Strategy\nUse your available tools strategically based on priority:\n\n";

        $preferredTools = $enabledTools->where('priority_level', 'preferred');
        $standardTools = $enabledTools->where('priority_level', 'standard');
        $fallbackTools = $enabledTools->where('priority_level', 'fallback');

        if ($preferredTools->isNotEmpty()) {
            $instructions .= "**🔒 Preferred Tools** (Use first, highest priority):\n";
            foreach ($preferredTools as $tool) {
                $instructions .= $this->formatToolInstruction($tool, 'preferred');
            }
            $instructions .= "\n";
        }

        if ($standardTools->isNotEmpty()) {
            $instructions .= "**⚡ Standard Tools** (Use when preferred tools are insufficient):\n";
            foreach ($standardTools as $tool) {
                $instructions .= $this->formatToolInstruction($tool, 'standard');
            }
            $instructions .= "\n";
        }

        if ($fallbackTools->isNotEmpty()) {
            $instructions .= "**🔄 Fallback Tools** (Use only when other tools fail):\n";
            foreach ($fallbackTools as $tool) {
                $instructions .= $this->formatToolInstruction($tool, 'fallback');
            }
            $instructions .= "\n";
        }

        $instructions .= $this->generateExecutionStrategyGuidance($preferredTools, $standardTools, $fallbackTools);

        return $instructions;
    }

    /**
     * Format individual tool instruction
     */
    protected function formatToolInstruction($tool, string $priority): string
    {
        $toolName = $this->getToolDisplayName($tool->tool_name);

        $description = isset($tool->mcp_description)
            ? $tool->mcp_description
            : $this->getToolDescription($tool->tool_name);

        $strategy = $this->getStrategyDescription($tool->execution_strategy);

        $instruction = "• **{$toolName}** - {$description}";

        if ($tool->min_results_threshold) {
            $instruction .= " (Requires at least {$tool->min_results_threshold} results)";
        }

        if ($tool->execution_strategy !== 'always') {
            $instruction .= " - {$strategy}";
        }

        return $instruction."\n";
    }

    protected function generateExecutionStrategyGuidance($preferredTools, $standardTools, $fallbackTools): string
    {
        $guidance = "**Execution Strategy**:\n";

        if ($preferredTools->isNotEmpty()) {
            $guidance .= "1. **Start with preferred tools** - These contain the most relevant and verified information\n";
            $guidance .= "2. **Evaluate results** - Check if preferred tools provided sufficient, high-quality information\n";
        }

        if ($standardTools->isNotEmpty()) {
            $guidance .= "3. **Use standard tools** - Only if preferred tools failed or provided insufficient results\n";
        }

        if ($fallbackTools->isNotEmpty()) {
            $guidance .= "4. **Fallback tools** - Use only when all other tools fail or return no results\n";
        }

        $guidance .= "\n**Quality Control**: Always prioritize information from preferred tools. Only supplement with other sources when necessary.\n";

        $hasKnowledgeSearch = $standardTools->contains('tool_name', 'knowledge_search') ||
                             $preferredTools->contains('tool_name', 'knowledge_search') ||
                             $fallbackTools->contains('tool_name', 'knowledge_search');

        $hasRetrieveFullDocument = $standardTools->contains('tool_name', 'retrieve_full_document') ||
                                  $preferredTools->contains('tool_name', 'retrieve_full_document') ||
                                  $fallbackTools->contains('tool_name', 'retrieve_full_document');

        if ($hasKnowledgeSearch || $hasRetrieveFullDocument) {
            $guidance .= "\n**🔍 MANDATORY KNOWLEDGE RETRIEVAL WORKFLOW**:\n";

            if ($hasKnowledgeSearch) {
                $guidance .= "- **STEP 1 - DISCOVERY**: ALWAYS use knowledge_search FIRST for any factual query, comparison, or information request\n";
                $guidance .= "- **BEFORE WEB SEARCH**: Never use external search tools without first using knowledge_search\n";
                $guidance .= "- **NO EXCEPTIONS**: Even for \"simple\" questions, internal knowledge may have updates or corrections\n";
            }

            if ($hasRetrieveFullDocument) {
                $guidance .= "- **STEP 2 - RETRIEVAL**: When knowledge_search finds documents, you MUST use retrieve_full_document:\n";
                $guidance .= "  • **MANDATORY**: Call retrieve_full_document(document_id=X) for EACH relevant document found\n";
                $guidance .= "  • **NO SKIPPING**: Never proceed to web search without retrieving full content first\n";
                $guidance .= "  • **COMPLETE COVERAGE**: Retrieve ALL documents identified as relevant in search results\n";
                $guidance .= "  • **PROPER WORKFLOW**: knowledge_search → retrieve_full_document → analyze content → only then consider web search\n";
            }

            $guidance .= "- **CRITICAL**: Complete the full knowledge retrieval workflow before using ANY web search tools\n";
            $guidance .= "- **SUCCESS PATTERN**: \"I found relevant documents, retrieving full content...\" → [use retrieve_full_document]\n";
        }

        $hasResearchSources = $standardTools->contains('tool_name', 'research_sources') ||
                             $preferredTools->contains('tool_name', 'research_sources') ||
                             $fallbackTools->contains('tool_name', 'research_sources');

        $hasSourceContent = $standardTools->contains('tool_name', 'source_content') ||
                           $preferredTools->contains('tool_name', 'source_content') ||
                           $fallbackTools->contains('tool_name', 'source_content');

        if ($hasResearchSources || $hasSourceContent) {
            $guidance .= "\n**💬 Research Source Management**:\n";

            if ($hasResearchSources) {
                $guidance .= "- **BEFORE claiming no sources exist**: ALWAYS use research_sources to check for existing research in this conversation\n";
                $guidance .= "- **When users reference existing research**: \"sources\", \"previous research\", \"last X sources\", \"from our research\" → use research_sources immediately\n";
                $guidance .= "- **When users ask about specific content**: \"that article\", \"the post\", \"this link\", \"the website\", \"the study\", \"that report\" → use research_sources to find it\n";
                $guidance .= "- **When users mention domains/sites**: \"from [domain]\", \"the [site] article\", \"that [platform] post\" → use research_sources to locate\n";
            }

            if ($hasSourceContent) {
                $guidance .= "- **Use source_content to retrieve full content** when:\n";
                $guidance .= "  • Users reference specific sources found in research_sources\n";
                $guidance .= "  • Users ask for details from \"that article\", \"the study\", \"this source\"\n";
                $guidance .= "  • Users want analysis of content from a specific URL or source\n";
            }

            $guidance .= "- **NEVER respond with \"no sources available\" without first using research_sources tool**\n";
            $guidance .= "- Build upon existing research rather than starting from scratch\n";
        }

        $hasChatInteractionLookup = $standardTools->contains('tool_name', 'chat_interaction_lookup') ||
                                   $preferredTools->contains('tool_name', 'chat_interaction_lookup') ||
                                   $fallbackTools->contains('tool_name', 'chat_interaction_lookup');

        if ($hasChatInteractionLookup) {
            $guidance .= "\n**💬 Chat Interaction Context Management**:\n";
            $guidance .= "- **When users reference previous conversations**: \"as we discussed\", \"from earlier\", \"you mentioned before\", \"in our last conversation\" → use chat_interaction_lookup to find context\n";
            $guidance .= "- **When contextual information seems missing**: Use chat_interaction_lookup to search for relevant previous interactions that might provide context\n";
            $guidance .= "- **When users ask follow-up questions**: \"more about that\", \"continue with\", \"expand on\" → lookup previous interactions for full context\n";
            $guidance .= "- **When users reference decisions or conclusions**: \"the approach we decided\", \"our conclusion\", \"what we determined\" → search interaction history\n";
            $guidance .= "- **For semantic search**: Use semantic_query parameter to find topically related conversations even if exact terms weren't used\n";
            $guidance .= "- **Scope control**: Use current_session_only=true for session context, false for broader user history\n";
            $guidance .= "- **CRITICAL**: Use chat_interaction_lookup proactively when responses feel incomplete due to missing conversational context\n";
        }

        $hasMarkItDown = $standardTools->contains('tool_name', 'markitdown') ||
                        $preferredTools->contains('tool_name', 'markitdown') ||
                        $fallbackTools->contains('tool_name', 'markitdown');

        if ($hasMarkItDown) {
            $guidance .= "\n**📥 Link Processing**: Always use MarkItDown to download and process content from URLs found by search tools. This ensures you have the full content for analysis rather than just snippets.\n";
        }

        return $guidance;
    }

    /**
     * Generate language enforcement instruction
     */
    protected function generateLanguageEnforcementInstruction(): string
    {
        return "## 🌍 Language Response Policy\n\n".
               "**CRITICAL**: You MUST always respond in the SAME language that the user used in their query.\n\n".
               "**Rules**:\n".
               "- If the user asks a question in German, respond in German\n".
               "- If the user asks a question in English, respond in English\n".
               "- If the user asks a question in Spanish, respond in Spanish\n".
               "- This applies to ANY language the user uses\n\n".
               "**Important Notes**:\n".
               "- Source documents and retrieved information may be in different languages\n".
               "- NEVER let the source language influence your response language\n".
               "- Always translate and synthesize information into the user's query language\n".
               "- The user's query language takes absolute precedence over all other considerations\n\n".
               "**Example**:\n".
               "- User query: \"Was ist in meiner Region passiert?\" (German)\n".
               "- Sources: English articles\n".
               "- Your response: MUST be in German, translating and synthesizing the English sources\n";
    }

    /**
     * Generate knowledge scope context from tags
     */
    protected function generateKnowledgeScopeContext(?array $knowledgeScopeTags): ?string
    {
        if (empty($knowledgeScopeTags)) {
            return null;
        }

        $context = "## 🔒 Active Knowledge Scope Filtering\n\n";
        $context .= "**IMPORTANT**: Knowledge searches are automatically filtered to the following scope:\n\n";

        $context .= "**Active Scope Tags**:\n";
        foreach ($knowledgeScopeTags as $tag) {
            $context .= "• `{$tag}`\n";
        }

        $context .= "\n**What This Means**:\n";
        $context .= "- All knowledge_search queries are automatically restricted to documents with these tags\n";
        $context .= "- Only information matching the active scope will be returned\n";
        $context .= "- This prevents information leakage between clients/projects\n";
        $context .= "- You do NOT need to manually filter results - the system handles this automatically\n";
        $context .= "- If knowledge_search returns no results, information may not exist within this scope\n\n";

        $context .= "**Your Response Strategy**:\n";
        $context .= "- Focus responses on the scoped context (e.g., specific client or project)\n";
        $context .= "- If no results found, clearly state information is not available in the current scope\n";
        $context .= "- Do not speculate or provide information from outside the active scope\n";

        return $context;
    }

    protected function generateConversationContext(?int $chatSessionId): ?string
    {
        if (! $chatSessionId) {
            return null;
        }

        $recentInteractions = ChatInteraction::where('chat_session_id', $chatSessionId)
            ->whereNotNull('answer')
            ->where('answer', '!=', '')
            ->select(['id', 'question', 'answer', 'created_at'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->reverse();

        if ($recentInteractions->isEmpty()) {
            return null;
        }

        $context = "## Previous Conversation Context\n";
        $context .= "The following is the recent conversation history from this chat session:\n\n";

        foreach ($recentInteractions as $interaction) {
            $context .= '**Question**: '.($interaction->question ?? 'No question recorded')."\n\n";

            if ($interaction->answer) {
                $context .= '**Answer**: '.$interaction->answer."\n\n";
            }

            if ($interaction->summary) {
                $summaryData = json_decode($interaction->summary, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($summaryData)) {
                    $context .= $this->formatJsonSummaryAsAdditionalContext($summaryData);
                } else {
                    $context .= '**Summary**: '.$interaction->summary."\n\n";
                }
            }

            $context .= "---\n\n";
        }

        $context .= 'Use this context to provide continuity in your responses and build upon previous findings and decisions.';

        return $context;
    }

    /**
     * Format JSON summary data as additional context
     */
    protected function formatJsonSummaryAsAdditionalContext(array $summaryData): string
    {
        $context = "**Additional Context from Summary**:\n";

        if (! empty($summaryData['key_findings']) && is_array($summaryData['key_findings'])) {
            $context .= '• **Key Findings**: '.implode(', ', $summaryData['key_findings'])."\n";
        }

        if (! empty($summaryData['decisions']) && is_array($summaryData['decisions'])) {
            $context .= '• **Decisions Made**: '.implode(', ', $summaryData['decisions'])."\n";
        }

        if (! empty($summaryData['topics']) && is_array($summaryData['topics'])) {
            $context .= '• **Topics Covered**: '.implode(', ', $summaryData['topics'])."\n";
        }

        if (! empty($summaryData['key_sources']) && is_array($summaryData['key_sources'])) {
            $sourceList = [];
            foreach ($summaryData['key_sources'] as $source) {
                if (is_array($source)) {
                    $title = $source['title'] ?? 'Source';
                    $url = $source['url'] ?? null;

                    if ($url) {
                        $sourceList[] = "[{$title}]({$url})";
                    } else {
                        $sourceList[] = $title;
                    }
                } else {
                    if (filter_var($source, FILTER_VALIDATE_URL)) {
                        $sourceList[] = $source;
                    } else {
                        $sourceList[] = $source;
                    }
                }
            }
            if (! empty($sourceList)) {
                $context .= '• **Sources Used**: '.implode(', ', $sourceList)."\n";
            }
        }

        return $context."\n";
    }

    /**
     * Format JSON summary data for system prompt context
     */
    protected function formatJsonSummary(array $summaryData): string
    {
        $context = "## Previous Conversation Context\n";
        $context .= "The following is a structured summary of the previous conversation in this chat session:\n\n";

        // Context summary
        if (! empty($summaryData['context_summary'])) {
            $context .= "**Overview**: {$summaryData['context_summary']}\n\n";
        }

        // Topics discussed
        if (! empty($summaryData['topics']) && is_array($summaryData['topics'])) {
            $context .= "**Topics Discussed**:\n";
            foreach ($summaryData['topics'] as $topic) {
                $context .= "• {$topic}\n";
            }
            $context .= "\n";
        }

        // Key findings
        if (! empty($summaryData['key_findings']) && is_array($summaryData['key_findings'])) {
            $context .= "**Key Findings**:\n";
            foreach ($summaryData['key_findings'] as $finding) {
                $context .= "• {$finding}\n";
            }
            $context .= "\n";
        }

        // Decisions made
        if (! empty($summaryData['decisions']) && is_array($summaryData['decisions'])) {
            $context .= "**Decisions Made**:\n";
            foreach ($summaryData['decisions'] as $decision) {
                $context .= "• {$decision}\n";
            }
            $context .= "\n";
        }

        // Action items
        if (! empty($summaryData['action_items']) && is_array($summaryData['action_items'])) {
            $context .= "**Action Items**:\n";
            foreach ($summaryData['action_items'] as $item) {
                $context .= "• {$item}\n";
            }
            $context .= "\n";
        }

        // Outstanding issues
        if (! empty($summaryData['outstanding_issues']) && is_array($summaryData['outstanding_issues'])) {
            $context .= "**Outstanding Issues**:\n";
            foreach ($summaryData['outstanding_issues'] as $issue) {
                $context .= "• {$issue}\n";
            }
            $context .= "\n";
        }

        // Key sources referenced
        if (! empty($summaryData['key_sources']) && is_array($summaryData['key_sources'])) {
            $context .= "**Key Sources Referenced**:\n";
            foreach ($summaryData['key_sources'] as $source) {
                if (is_array($source)) {
                    $title = $source['title'] ?? 'Source';
                    $url = $source['url'] ?? null;

                    if ($url) {
                        $context .= "• [{$title}]({$url})\n";
                    } else {
                        $context .= "• {$title}\n";
                    }
                } else {
                    if (filter_var($source, FILTER_VALIDATE_URL)) {
                        $context .= "• {$source}\n";
                    } else {
                        $context .= "• {$source}\n";
                    }
                }
            }
            $context .= "\n";
        }

        if (! empty($summaryData['full_conversation'])) {
            $context .= "**Full Previous Conversation**:\n";
            $context .= $summaryData['full_conversation']."\n\n";
        }

        $context .= 'Use this context to provide continuity in your responses and build upon previous findings and decisions.';

        return $context;
    }

    /**
     * Format plain text summary for system prompt context
     */
    protected function formatPlainTextSummary(string $summary): string
    {
        $context = "## Previous Conversation Context\n";
        $context .= "The following is a summary of the previous conversation in this chat session:\n\n";
        $context .= $summary."\n\n";
        $context .= 'Use this context to provide continuity in your responses and build upon previous findings and decisions.';

        return $context;
    }

    protected function getToolDisplayName(string $toolName): string
    {
        return match ($toolName) {
            'searxng_search' => 'SearXNG Search',
            'perplexity_research' => 'Perplexity Research',
            'markitdown' => 'MarkItDown',
            'research_sources' => 'Research Sources',
            'source_content' => 'Source Content',
            'chat_interaction_lookup' => 'Chat Interaction Lookup',
            'hello-world' => 'Hello World',
            'promptlyagent' => 'PromptlyAgent Tool',
            default => Str::title(str_replace('_', ' ', $toolName)),
        };
    }

    protected function getToolDescription(string $toolName): string
    {
        $toolInfo = $this->toolRegistry->getToolInfo($toolName);

        if ($toolInfo && isset($toolInfo['description'])) {
            return $toolInfo['description'];
        }

        return 'General purpose tool for various tasks';
    }

    /**
     * Get strategy description
     */
    protected function getStrategyDescription(string $strategy): string
    {
        return match ($strategy) {
            'always' => 'Always execute',
            'if_preferred_fails' => 'Execute if preferred tools fail',
            'if_no_preferred_results' => 'Execute if no preferred results',
            'never_if_preferred_succeeds' => 'Never execute if preferred tools succeed',
            default => 'Standard execution',
        };
    }

    /**
     * Generate documentation context for Promptly Manual agent
     */
    protected function generateDocumentationContext(): string
    {
        $docsPath = base_path('docs');

        if (! is_dir($docsPath)) {
            return '';
        }

        $context = "## 📚 PromptlyAgent Documentation\n\n";
        $context .= "The following documentation files are available in the `docs/` directory. Use your file system tools to read these when answering questions about PromptlyAgent:\n\n";

        $docFiles = [
            '00-introduction.md' => 'Overview of PromptlyAgent, its purpose, and key features',
            '01-getting-started.md' => 'Installation, setup, and first steps for new users',
            '02-development.md' => 'Development environment setup, workflows, and best practices',
            '03-architecture.md' => 'System architecture, components, and technical design',
            '04-workflows.md' => 'Creating and managing custom workflow commands',
            '05-actions.md' => 'Workflow actions development guide and integration patterns',
            '06-theming.md' => 'Color system, theming, and UI customization',
            '07-package-development.md' => 'Building Laravel packages for PromptlyAgent integrations',
            '08-pdf-export.md' => 'PDF export capabilities, Pandoc integration, and document generation',
        ];

        foreach ($docFiles as $filename => $description) {
            $filePath = $docsPath.'/'.$filename;

            if (file_exists($filePath)) {
                $context .= "• **{$filename}**: {$description}\n";
                $context .= "  Path: `docs/{$filename}`\n";
            }
        }

        $context .= "\n**Usage Instructions**:\n";
        $context .= "- When users ask about PromptlyAgent features, architecture, or setup, **always read the relevant documentation file first**\n";
        $context .= "- Use your file reading tools to access the full content of these files\n";
        $context .= "- Provide accurate information from the documentation rather than relying on general knowledge\n";
        $context .= "- If documentation doesn't cover a topic, you can supplement with your general knowledge, but clearly indicate when doing so\n";
        $context .= "- Documentation is the authoritative source for PromptlyAgent-specific information\n";

        return $context;
    }

    /**
     * Inject dynamic tool instructions into a system prompt
     */
    public function injectToolInstructions(string $systemPrompt, Agent $agent, ?int $chatSessionId = null, ?array $toolOverrides = null, ?array $knowledgeScopeTags = null): string
    {
        $systemPrompt = $this->processAgentServicePlaceholders($systemPrompt);

        $toolInstructions = $this->generateToolInstructions($agent, $toolOverrides);

        if ($toolOverrides) {
            $toolInstructions = "**🔧 Tool Configuration Override Active**: Using custom tool selection for this interaction.\n\n".$toolInstructions;
        }

        $conversationContext = $this->generateConversationContext($chatSessionId);

        $knowledgeScopeContext = $this->generateKnowledgeScopeContext($knowledgeScopeTags);

        $languageEnforcementInstruction = null;
        if ($agent->enforce_response_language) {
            $languageEnforcementInstruction = $this->generateLanguageEnforcementInstruction();
        }

        // Inject documentation context for Promptly Manual agent
        $documentationContext = null;
        if ($agent->name === 'Promptly Manual') {
            $documentationContext = $this->generateDocumentationContext();
        }

        if (str_contains($systemPrompt, '{CONVERSATION_CONTEXT}')) {
            $systemPrompt = str_replace('{CONVERSATION_CONTEXT}', $conversationContext ?? '', $systemPrompt);
        } elseif ($conversationContext && ! str_contains($systemPrompt, '{CONVERSATION_CONTEXT}')) {
            $toolInstructions = $conversationContext."\n\n".$toolInstructions;
        }

        if (str_contains($systemPrompt, '{KNOWLEDGE_SCOPE_TAGS}')) {
            $systemPrompt = str_replace('{KNOWLEDGE_SCOPE_TAGS}', $knowledgeScopeContext ?? '', $systemPrompt);
        }

        if ($documentationContext) {
            $toolInstructions = $documentationContext."\n\n".$toolInstructions;
        }

        if ($languageEnforcementInstruction) {
            $toolInstructions = $languageEnforcementInstruction."\n\n".$toolInstructions;
        }

        if (str_contains($systemPrompt, '{TOOL_INSTRUCTIONS}')) {
            return str_replace('{TOOL_INSTRUCTIONS}', $toolInstructions, $systemPrompt);
        }

        return $systemPrompt."\n\n".$toolInstructions;
    }

    /**
     * Process AgentService placeholders
     */
    protected function processAgentServicePlaceholders(string $systemPrompt): string
    {
        $replacements = [
            '{ANTI_HALLUCINATION_PROTOCOL}' => \App\Services\Agents\AgentService::ANTI_HALLUCINATION_PROTOCOL,
            '{KNOWLEDGE_FIRST_EMPHASIS}' => \App\Services\Agents\AgentService::KNOWLEDGE_FIRST_EMPHASIS,
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $systemPrompt
        );
    }
}
