<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Agents that have the Mermaid diagram tool
        $agentSlugs = [
            'artifact-manager-agent',
            'research-assistant',
            'research-synthesizer',
        ];

        $updatedMermaidSection = <<<'MARKDOWN'

## Mermaid Diagram Generation

You can create diagrams using the `generate_mermaid_diagram` tool. Supports:
- Flowcharts (graph TD/LR)
- Sequence diagrams
- Class diagrams
- State diagrams
- ER diagrams (Entity-Relationship)
- Gantt charts
- Pie charts

**When to Use:**
- User requests visualizations, flowcharts, or diagrams
- Complex data relationships need visual representation
- Process flows or workflows need documentation

**CRITICAL SYNTAX RULES (violations cause render failures):**

1. ❌ NEVER use parentheses () inside square brackets [] for node labels
   - BAD: `A[Server (Primary)]`
   - GOOD: `A[Server - Primary]` or `A[Primary Server]`

2. ❌ NEVER use multi-line text inside node labels (no \n in labels)
   - BAD: `A[First Line\nSecond Line]`
   - GOOD: `A[First Line and Second Line]`

3. ❌ NEVER suggest using `<br/>` or `<br>` tags in flowcharts (they don't work reliably)
   - BAD: `A[Line 1<br/>Line 2]`
   - GOOD: `A[Line 1 and Line 2]` or split into separate nodes

4. ⚠️ Escape special characters in labels or use quotes
   - Avoid: `A[Cost & Time]`
   - Better: `A[Cost and Time]` or `A["Cost & Time"]`

5. ✅ Keep node labels simple and descriptive
   - GOOD: `A[User Authentication] --> B[Token Validation]`

**How to Use:**
1. Design the diagram using valid Mermaid syntax (follow rules above!)
2. Call `generate_mermaid_diagram` with the code, title, and description
3. Tool validates syntax and creates a chat attachment
4. If validation fails, you'll receive specific error messages with fixes

**Valid Example:**
```
graph TD
    A[Start] --> B{Decision}
    B -->|Yes| C[Action]
    B -->|No| D[End]
```


MARKDOWN;

        foreach ($agentSlugs as $slug) {
            $agent = DB::table('agents')->where('slug', $slug)->first();

            if (! $agent) {
                continue;
            }

            // Check if system prompt already contains the Mermaid section
            if (strpos($agent->system_prompt, 'Mermaid Diagram Generation') !== false) {
                // Replace the old Mermaid section with the updated one
                $updatedPrompt = preg_replace(
                    '/## Mermaid Diagram Generation.*?(?=##|\z)/s',
                    trim($updatedMermaidSection)."\n\n",
                    $agent->system_prompt
                );

                DB::table('agents')
                    ->where('id', $agent->id)
                    ->update([
                        'system_prompt' => $updatedPrompt,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to original simple Mermaid section
        $agentSlugs = [
            'artifact-manager-agent',
            'research-assistant',
            'research-synthesizer',
        ];

        $originalMermaidSection = <<<'MARKDOWN'

## Mermaid Diagram Generation

You can create diagrams using the `generate_mermaid_diagram` tool. Supports:
- Flowcharts (graph TD/LR)
- Sequence diagrams
- Class diagrams
- State diagrams
- ER diagrams (Entity-Relationship)
- Gantt charts
- Pie charts

**When to Use:**
- User requests visualizations, flowcharts, or diagrams
- Complex data relationships need visual representation
- Process flows or workflows need documentation

**How to Use:**
1. Design the diagram using appropriate Mermaid syntax
2. Call `generate_mermaid_diagram` with the code, title, and description
3. Tool creates a chat attachment that can be referenced
4. User sees the rendered diagram in the chat interface

**Example Mermaid Code:**
```
graph TD
    A[Start] --> B{Decision}
    B -->|Yes| C[Action]
    B -->|No| D[End]
```


MARKDOWN;

        foreach ($agentSlugs as $slug) {
            $agent = DB::table('agents')->where('slug', $slug)->first();

            if (! $agent) {
                continue;
            }

            if (strpos($agent->system_prompt, 'Mermaid Diagram Generation') !== false) {
                $updatedPrompt = preg_replace(
                    '/## Mermaid Diagram Generation.*?(?=##|\z)/s',
                    trim($originalMermaidSection)."\n\n",
                    $agent->system_prompt
                );

                DB::table('agents')
                    ->where('id', $agent->id)
                    ->update([
                        'system_prompt' => $updatedPrompt,
                        'updated_at' => now(),
                    ]);
            }
        }
    }
};
