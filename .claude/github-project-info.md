# GitHub Project Board Information

## Project: PromptlyAgentAIFirstRelease

This is the main project board for tracking issues related to the first AI-powered release of PromptlyAgent.

### Kanban Board Columns

| Status | Description | Estimate Limit |
|--------|-------------|----------------|
| **Backlog** | This item hasn't been started | 0 (default) |
| **Ready** | This is ready to be picked up | 0 (default) |
| **In progress** | This is actively being worked on | 0/3 |
| **In review** | This item is in review | 0/5 |
| **Done** | This has been completed | 0 (default) |

### Workflow

Issues typically flow through the board in this order:
1. **Backlog** → Initial state for new issues
2. **Ready** → Issue is well-defined and ready to be worked on
3. **In progress** → Actively being developed (max 3 items)
4. **In review** → Code is complete and under review (max 5 items)
5. **Done** → Issue is completed and closed

### Additional Views

The project also has the following views:
- **Backlog** (default view)
- **Priority board**
- **Team items**
- **Roadmap**
- **My items**

### Working with the Project Board

Use the GitHub CLI (`gh`) to interact with the project board:

```bash
# List all issues in the project
gh project item-list <project-number>

# Move an issue to a different status
gh issue edit <issue-number> --add-project "PromptlyAgentAIFirstRelease"

# View project details
gh project view <project-number>
```

### Context for AI Assistant

When the user mentions:
- "move to in progress" → Update issue status to **In progress**
- "mark as review" or "in review" → Update issue status to **In review**
- "mark as done" → Update issue status to **Done** (and usually close the issue)
- "move to backlog" → Update issue status to **Backlog**
- "mark as ready" → Update issue status to **Ready**
