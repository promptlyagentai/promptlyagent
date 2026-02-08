# Introduction

Welcome to **PromptlyAgent** - an AI-powered research and knowledge management platform built for developers who want to harness the power of multi-agent orchestration, RAG (Retrieval-Augmented Generation), and real-time streaming in their applications.

## Prerequisites

Before diving into concepts, make sure you've completed the [Getting Started](01-getting-started.md) guide to install and run PromptlyAgent locally.

## What is PromptlyAgent?

PromptlyAgent is a comprehensive Laravel-based platform that combines:

- **AI Agent Orchestration** - Build specialized agents that can work together
- **Knowledge Management (RAG)** - Semantic search over your documents and data
- **Real-time Streaming** - Live AI responses via Server-Sent Events
- **Extensible Architecture** - Self-registering packages for custom integrations

Built on the **TALL stack** (Tailwind, Alpine.js, Laravel, Livewire), PromptlyAgent provides both a web interface for users and a comprehensive REST API for developers.

**📋 For a complete feature list**, see the [README](https://github.com/promptlyagentai/promptlyagent#features).

---

## Core Concepts

### What are Agents?

**Agents** are AI-powered entities that can perform specific tasks using tools and knowledge sources. Think of them as specialized AI assistants with:

- **Model Provider** - The AI backend (OpenAI GPT, Anthropic Claude, Google Gemini, AWS Bedrock)
- **System Prompt** - Instructions defining the agent's behavior and personality
- **Tools** - Capabilities the agent can use (web search, file operations, API calls, etc.)
- **Knowledge Sources** - Documents and data the agent can reference (RAG)

**Example Use Cases:**
- **Research Agent** - Equipped with web search tool + academic papers knowledge base
- **Code Review Agent** - Equipped with code analysis tools + style guide knowledge
- **Customer Support Agent** - Equipped with CRM tools + product documentation knowledge

Agents are configured via the web interface or programmatically using Eloquent models.

### What is RAG (Retrieval-Augmented Generation)?

**RAG** enhances AI responses by giving agents access to your specific documents and data. Here's how it works:

1. **Indexing** - Documents are processed and converted to vector embeddings
2. **Storage** - Embeddings stored in Meilisearch for fast semantic search
3. **Retrieval** - When an agent needs context, relevant documents are retrieved
4. **Generation** - Retrieved content is included in the AI's prompt for accurate responses

**Why RAG Matters:**
- AI responses grounded in YOUR data, not just training data
- Reduces hallucinations by providing factual sources
- Keeps information current without retraining models
- Citations and source tracking for transparency

**Knowledge Document Types:**
- **Files** - PDFs, Word docs, text files, code files
- **Text** - Direct text input via web interface or API
- **External URLs** - Automatically fetched and refreshed on schedule

### What are Workflows?

**Workflows** orchestrate multiple agents to handle complex tasks that require different specializations. PromptlyAgent supports four execution strategies:

**1. Simple** - Single agent execution
```
User Query → Agent → Response
```

**2. Sequential** - Agents process results in order
```
User Query → Agent 1 → Agent 2 → Agent 3 → Final Response
```

**3. Parallel** - Multiple agents work simultaneously
```
User Query → [Agent 1, Agent 2, Agent 3] → Combine Results → Response
```

**4. Mixed** - Combination of parallel and sequential
```
User Query → [Agent 1, Agent 2] → Agent 3 → Response
```

**Real-World Example:**
```
Research Topic
  ↓
[Web Search Agent, Academic Papers Agent] (parallel)
  ↓
Synthesis Agent (sequential - combines findings)
  ↓
QA Agent (sequential - validates accuracy)
  ↓
Final Report
```

Workflows are powered by Laravel's batch processing system with Horizon for monitoring.

### PDF Export & Document Generation

Generate professional documents from conversations and content:

- **Multiple Templates** - Eisvogel (technical), Elegant (business), Academic (papers)
- **YAML Metadata** - Full control over styling with frontmatter blocks
- **Format Support** - PDF, DOCX, ODT, LaTeX source

👉 **[Read the full PDF Export documentation](08-pdf-export.md)** for YAML metadata examples, template options, and advanced features.

## Architecture

PromptlyAgent is built with a modular architecture designed for scalability and extensibility.

📚 **For detailed architecture, component diagrams, and system design**: See the [Architecture Guide](03-architecture.md)

## Use Cases

### Research & Analysis
- Daily news digests with multi-topic research
- Competitive intelligence gathering
- Market research and trend analysis
- Academic research assistance

### Knowledge Management
- Internal documentation search
- Code repository analysis
- Customer support knowledge bases
- Compliance and regulatory document management

### Content Creation
- Blog post research and drafting
- Social media content generation
- Email campaign creation
- Report generation with data synthesis

### Integration & Automation
- Webhook-triggered research workflows
- Scheduled digest delivery
- Slack bot integration
- Email notification systems

## Technology Stack

### Backend & Infrastructure
- **Laravel 12** - PHP framework
- **PHP 8.4** - Latest PHP with modern features
- **Nginx** - High-performance web server
- **PHP-FPM** - FastCGI process manager
- **Supervisor** - Process control system
- **MySQL 8.0** - Primary database
- **Redis Alpine** - Caching and queue backend
- **Meilisearch v1.15** - Fast semantic search engine
- **Horizon** - Queue worker monitoring (dedicated container)
- **Reverb** - Laravel WebSocket server (dedicated container)
- **Mailpit** - Email testing tool

### Specialized Services
- **MarkItDown** - Document-to-markdown conversion service (2 instances, load balanced)
- **SearXNG** - Meta-search engine for web queries (2 instances, load balanced)

### Frontend
- **Livewire 3** - Server-driven reactive components
- **Volt** - Single-file component syntax
- **Flux UI (Free)** - Modern component library
- **Tailwind CSS 4** - Utility-first styling
- **Alpine.js** - Client-side reactivity

### AI & Integration
- **Prism-PHP** - Multi-provider AI SDK
- **OpenAI API** - GPT models
- **Anthropic API** - Claude models
- **AWS Bedrock** - Multi-model access
- **Laravel Sanctum** - API authentication
- **Laravel Scout** - Search integration

## Documentation Structure

This documentation is organized to gradually introduce you to PromptlyAgent:

1. **Getting Started** - Install and run the project locally
2. **Development** - Development workflow, architecture, and best practices
3. **Architecture** - Deep dive into system design and components
4. **Workflows** - Creating custom multi-agent workflow commands
5. **Actions** - Building workflow actions for data transformation
6. **Theming** - Color system and UI customization
7. **Package Development** - Building integration packages
8. **API Reference** - Complete REST API documentation

## Quick Links

- **GitHub Repository**: [promptlyagentai/promptlyagent](https://github.com/promptlyagentai/promptlyagent)
- **API Documentation**: [/docs](/docs) (this documentation)
- **OpenAPI Spec**: [/docs.openapi](/docs.openapi)
- **Postman Collection**: [/docs.postman](/docs.postman)

## Getting Help

- **GitHub Issues**: Report bugs and request features
- **Discussions**: Ask questions and share ideas
- **Documentation**: Search this documentation for answers

## License

PromptlyAgent is open-source software licensed under the MIT license.

---

## Next Steps

Now that you understand the core concepts, explore these guides:

**📚 Continue Learning:**
- **[Development Guide](02-development.md)** - Day-to-day development workflow and commands
- **[Architecture](03-architecture.md)** - Deep dive into system architecture and design
- **[Workflows](04-workflows.md)** - Build custom multi-agent workflows
- **[Package Development](07-package-development.md)** - Create custom integration packages

**💬 Need Help?**
- Visit our [GitHub Issues](https://github.com/promptlyagentai/promptlyagent/issues)
- Email: security@promptlyagent.ai (security issues)
