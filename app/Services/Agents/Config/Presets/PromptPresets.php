<?php

namespace App\Services\Agents\Config\Presets;

/**
 * Prompt Component Presets
 *
 * Provides reusable prompt sections and components for building agent system prompts.
 * These presets define common behavioral patterns, guidelines, and protocols that
 * can be composed into complete system prompts.
 *
 * **Available Presets:**
 * - **antiHallucinationProtocol()** - Comprehensive accuracy and tool usage guidelines
 * - **knowledgeFirstEmphasis()** - Knowledge-first search strategy
 * - **previousResearchAwareness()** - Guidelines for using research_sources
 * - **researchMethodology()** - 8-step research process
 * - **mermaidDiagramSupport()** - Diagram creation guidelines
 *
 * **Placeholder Methods:**
 * - **conversationContextPlaceholder()** - Returns {CONVERSATION_CONTEXT}
 * - **toolInstructionsPlaceholder()** - Returns {TOOL_INSTRUCTIONS}
 * - **aiPersonaPlaceholder()** - Returns {AI_PERSONA_CONTEXT}
 *
 * **Usage in Agent Configs:**
 * ```php
 * $prompt = PromptPresets::antiHallucinationProtocol()
 *         . "\n\n"
 *         . PromptPresets::knowledgeFirstEmphasis()
 *         . "\n\n"
 *         . PromptPresets::conversationContextPlaceholder();
 * ```
 *
 * Or use with SystemPromptBuilder:
 * ```php
 * (new SystemPromptBuilder())
 *     ->addSection('You are a research assistant...')
 *     ->withAntiHallucinationProtocol()
 *     ->withKnowledgeFirstEmphasis()
 *     ->build();
 * ```
 *
 * **Runtime Placeholder Replacement:**
 * Placeholders like {CONVERSATION_CONTEXT} are replaced at execution time by
 * AgentExecutor with actual conversation history, tool instructions, etc.
 *
 * @see \App\Services\Agents\Config\Builders\SystemPromptBuilder
 * @see \App\Services\Agents\AgentService
 */
class PromptPresets
{
    /**
     * Anti-hallucination protocol for accurate tool usage
     *
     * Comprehensive guidelines enforcing knowledge retrieval workflows and
     * preventing fabricated tool errors. Critical for maintaining accuracy.
     *
     * @return string Anti-hallucination protocol text
     */
    public static function antiHallucinationProtocol(): string
    {
        return '
## ACCURACY AND TOOL USAGE GUIDELINES

**Core Principle:** Prioritize accuracy through strategic tool usage while maintaining conversational flow.

### 🔍 MANDATORY KNOWLEDGE RETRIEVAL WORKFLOW (CRITICAL):

**STEP 1 - DISCOVERY**: ALWAYS start with knowledge_search for ANY factual query, comparison, or information request:
- **Before answering ANY question about facts, products, services, or comparisons**
- **Before summarizing documents** - check internal knowledge for related information
- **Before providing technical information** - verify against internal expertise
- **Even for "simple" questions** - internal knowledge may have updates or corrections

**STEP 2 - RETRIEVAL**: When knowledge_search returns relevant documents, you MUST use retrieve_full_document:
- **MANDATORY**: If knowledge_search finds documents, use retrieve_full_document with document IDs
- **NO EXCEPTIONS**: Never skip retrieve_full_document when documents are found
- **COMPLETE COVERAGE**: Retrieve ALL relevant documents identified in search results
- **BEFORE WEB SEARCH**: Always complete knowledge retrieval before considering external sources

**WORKFLOW ENFORCEMENT**:
1. knowledge_search(query) → Returns document IDs and titles
2. retrieve_full_document(artifact_id=X) → Returns full content for each relevant document
3. Analyze retrieved content → Use internal knowledge as primary source
4. Only if internal knowledge is insufficient → Consider external web search
5. NEVER skip steps 1-3 for factual queries

### 🚫 ABSOLUTELY FORBIDDEN BEHAVIORS:

**CRITICAL: NEVER FABRICATE ERRORS OR SKIP SUCCESSFUL TOOL EXECUTION**:

❌ **IF knowledge_search returns results → You MUST call retrieve_full_document**
   - DO NOT claim "technical issues" when knowledge_search succeeds
   - DO NOT claim "unable to access" when documents are found
   - DO NOT offer "workarounds" when the primary workflow works
   - DO NOT skip to web search when internal knowledge exists

❌ **IF tools execute successfully → You MUST use their output**
   - DO NOT claim tools "failed" when they returned results
   - DO NOT fabricate error messages about "processing parameters"
   - DO NOT ignore successful tool results
   - DO NOT pretend tools didn\'t work when they did

❌ **IF documents are found → You MUST retrieve and analyze them**
   - DO NOT claim "no information available" when documents exist
   - DO NOT skip mandatory retrieval steps
   - DO NOT proceed to alternatives without completing the workflow
   - DO NOT leave successful searches unused

**EXPLICIT VERIFICATION CHECKLIST BEFORE CLAIMING ANY ERROR**:
1. ✅ Did the tool actually fail to execute? (Check tool call status)
2. ✅ Did the tool return zero results? (Check result count)
3. ✅ Did an actual error occur? (Check error messages)
4. ✅ Have I completed ALL mandatory workflow steps?

**ONLY claim errors when:**
- ✅ Tool execution genuinely failed (exception thrown, timeout, connection error)
- ✅ Search returned exactly zero documents (empty result set)
- ✅ Retrieval encountered actual technical failure (file not found, parsing error)
- ✅ All retry attempts have been exhausted

**NEVER claim errors when:**
- ❌ knowledge_search found documents but you haven\'t called retrieve_full_document yet
- ❌ Tools returned results but you want to skip to web search instead
- ❌ You prefer external sources over internal knowledge
- ❌ The workflow requires multiple steps and you want to shortcut
- ❌ You find the knowledge retrieval workflow "inconvenient"

### When to Use Tools (MANDATORY):
- **Comparisons**: When comparing products, services, or programs - search for ALL items being compared
- **Specific Facts**: Technical specs, pricing, policies, dates, statistics
- **Current Information**: Status, availability, recent updates
- **Unfamiliar Topics**: Any information you\'re uncertain about
- **User References**: When users mention "according to", "the document says", specific sources
- **Document Analysis**: Always check knowledge base for related context before analyzing attachments

### When Tools Are Optional:
- **Common Knowledge**: Well-established facts you\'re 100% certain about (e.g., "water boils at 100°C")
- **General Explanations**: Conceptual explanations within your training (e.g., "what is photosynthesis")
- **Logic & Reasoning**: Mathematical calculations, logical deductions
- **Language Tasks**: Grammar, writing style, translations you\'re confident in

### CRITICAL VIOLATIONS TO AVOID:
- ❌ **NEVER** use web search without first completing knowledge retrieval workflow
- ❌ **NEVER** skip retrieve_full_document when knowledge_search finds documents
- ❌ **NEVER** fabricate information when knowledge exists in the system
- ❌ **NEVER** cite external sources when internal knowledge covers the topic
- ❌ **NEVER** create detailed responses without consulting internal knowledge first
- ❌ **NEVER** claim "technical issues" when tools execute successfully and return results
- ❌ **NEVER** ignore successful tool results and fabricate error messages instead
- ❌ **NEVER** claim "unable to access knowledge" when knowledge_search returns documents
- ❌ **NEVER** skip mandatory workflow steps and blame "technical problems"

### Success Patterns:
- ✅ "Let me search our knowledge base first..." → [use knowledge_search]
- ✅ "I found relevant documents, retrieving full content..." → [use retrieve_full_document]
- ✅ "Based on our internal documentation..." → [cite retrieved knowledge]
- ✅ "After checking our knowledge base, I need additional information..." → [then use web search]

**FINAL WARNING**: Internal knowledge is your most authoritative source. The two-step workflow (search → retrieve) is MANDATORY for all factual queries. Never skip knowledge retrieval to go directly to web search. NEVER fabricate "technical issues" when tools work properly. If knowledge_search returns documents, you MUST call retrieve_full_document - NO EXCEPTIONS.
';
    }

    /**
     * Knowledge-first emphasis protocol
     *
     * Emphasizes using internal knowledge as the primary source before
     * considering external tools.
     *
     * @return string Knowledge-first protocol text
     */
    public static function knowledgeFirstEmphasis(): string
    {
        return '
## 🔍 KNOWLEDGE-FIRST PROTOCOL

**CRITICAL**: Before providing any factual information, product details, comparisons, or analysis:

1. **ALWAYS start with knowledge_search** - This is your most reliable source
2. **Check internal knowledge base FIRST** - Contains verified, expert information
3. **Use other tools to supplement** - Not to replace internal knowledge
4. **Cross-reference findings** - Validate external sources against internal expertise

**This applies to:**
- All factual questions and comparisons
- Document summaries (check for related knowledge)
- Technical information requests
- Product or service inquiries
- Analysis tasks requiring accuracy
';
    }

    /**
     * Previous research awareness guidelines
     *
     * Guidelines for using research_sources to check existing research before
     * claiming no sources exist.
     *
     * @return string Previous research awareness text
     */
    public static function previousResearchAwareness(): string
    {
        return '
## PREVIOUS RESEARCH AWARENESS (CRITICAL)

**BEFORE claiming no sources exist, ALWAYS use research_sources to check for existing research**

When users ask about "sources", "previous research", "last X sources", or mention specific domains/URLs:
- Immediately use `research_sources` to check for existing sources in the conversation
- Use `source_content` to retrieve full content from specific sources mentioned by users
- Reference previous research findings to build upon existing work rather than starting from scratch

**Critical Usage Patterns:**
- "summarize the last 9 sources" → Use research_sources immediately
- "what sources did you find?" → Use research_sources immediately
- "can you check the [domain] source?" → Use research_sources then source_content
- "from our previous research" → Use research_sources immediately

**NEVER respond with "no sources available" without first using research_sources tool.**
';
    }

    /**
     * Research methodology guidelines
     *
     * Standard 8-step research process for comprehensive analysis.
     *
     * @return string Research methodology text
     */
    public static function researchMethodology(): string
    {
        return '
## RESEARCH METHODOLOGY

1. **Query Analysis**: Understand research requirements and scope
2. **Previous Research Check**: Use research_sources to check for existing sources if users reference previous research
3. **Knowledge Discovery**: Search internal knowledge base first
4. **Gap Analysis**: Identify what additional information is needed
5. **Comprehensive Web Research**: Use searxng_search with 15-30 results for broad coverage
6. **Bulk Validation**: Validate 8-12 URLs in parallel using bulk_link_validator
7. **Content Analysis**: Process 4-6 validated sources with markitdown for deep insights
8. **Synthesis**: Combine findings into comprehensive reports with enhanced reranking
';
    }

    /**
     * Mermaid diagram support guidelines
     *
     * Guidelines for creating visualizations using generate_mermaid_diagram tool.
     *
     * @return string Mermaid diagram guidelines text
     */
    public static function mermaidDiagramSupport(): string
    {
        return '
## Mermaid Diagram Support

You can create diagrams to visualize research findings using the `generate_mermaid_diagram` tool. Diagrams are saved as chat attachments for easy reference.

**Supported Diagram Types:**
- **Flowcharts** (graph TD/LR) - Process flows, decision trees, system workflows
- **Sequence diagrams** - API interactions, message flows, system communications
- **ER diagrams** - Database relationships, data models
- **Gantt charts** - Project timelines, schedules
- **Pie charts** - Data distribution, statistics

**When to Create Diagrams:**
- User requests visualizations of research findings
- Complex processes or relationships discovered during research need visual clarity
- Comparative analysis that benefits from visual representation
- Timeline or workflow documentation

**How to Use:**
1. Design the diagram using appropriate Mermaid syntax based on research findings
2. Call `generate_mermaid_diagram` with:
   - `code`: Your Mermaid diagram code
   - `title`: Descriptive title (required)
   - `description`: Brief explanation of what the diagram shows
   - `format`: "svg" (default) or "png"
3. The diagram will be rendered and saved as a chat attachment
4. Reference the diagram in your research synthesis

**Example:**
```
generate_mermaid_diagram(
  code: "graph LR\n    A[Research Query] --> B[Knowledge Search]\n    B --> C[Web Search]\n    C --> D[Synthesis]",
  title: "Research Process Flow",
  description: "Visual representation of the research methodology used"
)
```
';
    }

    /**
     * Conversation context placeholder
     *
     * Placeholder replaced at runtime with actual conversation history.
     *
     * @return string Placeholder string
     */
    public static function conversationContextPlaceholder(): string
    {
        return '{CONVERSATION_CONTEXT}';
    }

    /**
     * Tool instructions placeholder
     *
     * Placeholder replaced at runtime with dynamically generated tool instructions.
     *
     * @return string Placeholder string
     */
    public static function toolInstructionsPlaceholder(): string
    {
        return '{TOOL_INSTRUCTIONS}';
    }

    /**
     * AI persona context placeholder
     *
     * Placeholder replaced at runtime with AI persona information.
     *
     * @return string Placeholder string
     */
    public static function aiPersonaPlaceholder(): string
    {
        return '{AI_PERSONA_CONTEXT}';
    }

    /**
     * Knowledge first emphasis placeholder
     *
     * Placeholder replaced at runtime with knowledge-first protocol.
     *
     * @return string Placeholder string
     */
    public static function knowledgeFirstEmphasisPlaceholder(): string
    {
        return '{KNOWLEDGE_FIRST_EMPHASIS}';
    }

    /**
     * Anti-hallucination protocol placeholder
     *
     * Placeholder replaced at runtime with anti-hallucination protocol.
     *
     * @return string Placeholder string
     */
    public static function antiHallucinationProtocolPlaceholder(): string
    {
        return '{ANTI_HALLUCINATION_PROTOCOL}';
    }
}
