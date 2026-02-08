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
        // Agents that should have the Mermaid diagram tool
        $agentSlugs = [
            'artifact-manager-agent',
            'research-assistant',
            'research-synthesizer',
        ];

        $mermaidSection = <<<'MARKDOWN'

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

            // Check if tool already exists for this agent
            $existingTool = DB::table('agent_tools')
                ->where('agent_id', $agent->id)
                ->where('tool_name', 'generate_mermaid_diagram')
                ->exists();

            if (! $existingTool) {
                // Add the generate_mermaid_diagram tool
                DB::table('agent_tools')->insert([
                    'agent_id' => $agent->id,
                    'tool_name' => 'generate_mermaid_diagram',
                    'tool_config' => json_encode([]),
                    'enabled' => true,
                    'execution_order' => 15,
                    'priority_level' => 'standard',
                    'execution_strategy' => 'always',
                    'min_results_threshold' => null,
                    'max_execution_time' => 60,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Update system prompt if it doesn't already contain Mermaid instructions
            if (strpos($agent->system_prompt, 'Mermaid Diagram Generation') === false) {
                $updatedPrompt = $agent->system_prompt;

                // Try to insert before "### Image Embedding" if it exists (Artifact Manager)
                if (strpos($updatedPrompt, '### Image Embedding (Three Methods)') !== false) {
                    $updatedPrompt = str_replace(
                        '### Image Embedding (Three Methods)',
                        trim($mermaidSection)."\n\n".'### Image Embedding (Three Methods)',
                        $updatedPrompt
                    );
                } else {
                    // For other agents, append to the end
                    $updatedPrompt .= "\n\n".trim($mermaidSection);
                }

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
        // Agents that should have the Mermaid diagram tool removed
        $agentSlugs = [
            'artifact-manager-agent',
            'research-assistant',
            'research-synthesizer',
        ];

        foreach ($agentSlugs as $slug) {
            $agent = DB::table('agents')->where('slug', $slug)->first();

            if (! $agent) {
                continue;
            }

            // Remove the generate_mermaid_diagram tool
            DB::table('agent_tools')
                ->where('agent_id', $agent->id)
                ->where('tool_name', 'generate_mermaid_diagram')
                ->delete();

            // Remove the Mermaid section from system prompt
            $currentPrompt = $agent->system_prompt;

            // Remove the entire "## Mermaid Diagram Generation" section
            $updatedPrompt = preg_replace(
                '/## Mermaid Diagram Generation.*?(?=##)/s',
                '',
                $currentPrompt
            );

            // Clean up extra whitespace
            $updatedPrompt = preg_replace('/\n{3,}/', "\n\n", $updatedPrompt);

            DB::table('agents')
                ->where('id', $agent->id)
                ->update([
                    'system_prompt' => trim($updatedPrompt),
                    'updated_at' => now(),
                ]);
        }
    }
};
