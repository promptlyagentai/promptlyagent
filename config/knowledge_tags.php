<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Knowledge Base Tags Configuration
    |--------------------------------------------------------------------------
    |
    | This file defines the standard taxonomy for knowledge base tagging.
    | Tags use a two-level format: scope:value (e.g., type:meeting-notes)
    |
    | Scopes provide hierarchical organization and enable precise filtering:
    | - Use single scope for broad filtering: type:policy
    | - Combine scopes for precise filtering: type:policy + confidentiality:internal
    |
    */

    'tags' => [
        // ============================================================
        // TYPE: Document/Content Types
        // ============================================================
        'type:meeting-notes' => [
            'description' => 'Meeting minutes, discussions, and decisions',
            'color' => 'blue',
        ],
        'type:policy' => [
            'description' => 'Company policies, guidelines, and procedures',
            'color' => 'red',
        ],
        'type:service' => [
            'description' => 'Service documentation and specifications',
            'color' => 'purple',
        ],
        'type:project' => [
            'description' => 'Project-specific documentation',
            'color' => 'indigo',
        ],
        'type:client' => [
            'description' => 'Client-specific information and documentation',
            'color' => 'green',
        ],
        'type:tutorial' => [
            'description' => 'How-to guides and tutorials',
            'color' => 'cyan',
        ],
        'type:reference' => [
            'description' => 'Reference documentation and API docs',
            'color' => 'slate',
        ],
        'type:troubleshooting' => [
            'description' => 'Problem-solving guides and known issues',
            'color' => 'orange',
        ],
        'type:proposal' => [
            'description' => 'Project proposals and RFPs',
            'color' => 'pink',
        ],
        'type:contract' => [
            'description' => 'Contracts and legal agreements',
            'color' => 'red',
        ],
        'type:onboarding' => [
            'description' => 'Employee and client onboarding materials',
            'color' => 'teal',
        ],
        'type:changelog' => [
            'description' => 'Version history and release notes',
            'color' => 'lime',
        ],
        'type:architecture' => [
            'description' => 'System architecture and design documents',
            'color' => 'purple',
        ],
        'type:runbook' => [
            'description' => 'Operational procedures and runbooks',
            'color' => 'amber',
        ],
        'type:postmortem' => [
            'description' => 'Incident postmortems and retrospectives',
            'color' => 'rose',
        ],
        'type:template' => [
            'description' => 'Reusable templates and boilerplates',
            'color' => 'violet',
        ],
        'type:checklist' => [
            'description' => 'Checklists and process verification',
            'color' => 'emerald',
        ],
        'type:report' => [
            'description' => 'Reports and analytics',
            'color' => 'sky',
        ],
        'type:presentation' => [
            'description' => 'Presentations and slide decks',
            'color' => 'fuchsia',
        ],
        'type:specification' => [
            'description' => 'Technical and functional specifications',
            'color' => 'indigo',
        ],
        'type:memo' => [
            'description' => 'Internal memos and communications',
            'color' => 'blue',
        ],
        'type:announcement' => [
            'description' => 'Company announcements and updates',
            'color' => 'yellow',
        ],
        'type:faq' => [
            'description' => 'Frequently asked questions and answers',
            'color' => 'teal',
        ],
        'type:decision-record' => [
            'description' => 'Architectural decision records (ADRs) and decisions',
            'color' => 'purple',
        ],
        'type:research' => [
            'description' => 'Research documents and findings',
            'color' => 'indigo',
        ],
        'type:case-study' => [
            'description' => 'Case studies and success stories',
            'color' => 'green',
        ],
        'type:best-practice' => [
            'description' => 'Best practices and standards',
            'color' => 'emerald',
        ],
        'type:lesson-learned' => [
            'description' => 'Lessons learned and retrospectives',
            'color' => 'orange',
        ],
        'type:sop' => [
            'description' => 'Standard operating procedures',
            'color' => 'slate',
        ],
        'type:training-material' => [
            'description' => 'Training materials and educational content',
            'color' => 'cyan',
        ],
        'type:wiki' => [
            'description' => 'Wiki articles and knowledge base entries',
            'color' => 'sky',
        ],
        'type:communication' => [
            'description' => 'General communications and correspondence',
            'color' => 'pink',
        ],

        // ============================================================
        // DISCIPLINE: Professional Disciplines & Roles
        // ============================================================
        'discipline:frontend-engineering' => [
            'description' => 'Frontend development, UI implementation',
            'color' => 'blue',
        ],
        'discipline:backend-engineering' => [
            'description' => 'Backend development, APIs, databases',
            'color' => 'indigo',
        ],
        'discipline:system-engineering' => [
            'description' => 'Infrastructure, DevOps, system architecture',
            'color' => 'slate',
        ],
        'discipline:mobile-engineering' => [
            'description' => 'iOS, Android, mobile development',
            'color' => 'purple',
        ],
        'discipline:data-engineering' => [
            'description' => 'Data pipelines, warehousing, analytics',
            'color' => 'cyan',
        ],
        'discipline:security-engineering' => [
            'description' => 'Security, compliance, penetration testing',
            'color' => 'red',
        ],
        'discipline:qa-testing' => [
            'description' => 'Quality assurance and testing',
            'color' => 'green',
        ],
        'discipline:devops' => [
            'description' => 'DevOps, CI/CD, automation',
            'color' => 'orange',
        ],
        'discipline:ux-design' => [
            'description' => 'User experience design and research',
            'color' => 'pink',
        ],
        'discipline:visual-design' => [
            'description' => 'Visual design, branding, graphics',
            'color' => 'rose',
        ],
        'discipline:product-management' => [
            'description' => 'Product strategy and roadmap',
            'color' => 'amber',
        ],
        'discipline:project-management' => [
            'description' => 'Project coordination and delivery',
            'color' => 'orange',
        ],
        'discipline:technical-writing' => [
            'description' => 'Documentation and content creation',
            'color' => 'teal',
        ],
        'discipline:sales' => [
            'description' => 'Sales processes and customer acquisition',
            'color' => 'green',
        ],
        'discipline:marketing' => [
            'description' => 'Marketing strategies and campaigns',
            'color' => 'pink',
        ],
        'discipline:customer-success' => [
            'description' => 'Customer support and success',
            'color' => 'blue',
        ],
        'discipline:customer-support' => [
            'description' => 'Technical support and troubleshooting',
            'color' => 'cyan',
        ],
        'discipline:finance' => [
            'description' => 'Financial planning and accounting',
            'color' => 'green',
        ],
        'discipline:legal' => [
            'description' => 'Legal matters and compliance',
            'color' => 'red',
        ],
        'discipline:hr' => [
            'description' => 'Human resources and recruiting',
            'color' => 'purple',
        ],
        'discipline:operations' => [
            'description' => 'Business operations and processes',
            'color' => 'slate',
        ],

        // ============================================================
        // CONFIDENTIALITY: Sensitivity Levels
        // ============================================================
        'confidentiality:public' => [
            'description' => 'Publicly shareable information',
            'color' => 'green',
        ],
        'confidentiality:internal' => [
            'description' => 'Internal company use only',
            'color' => 'blue',
        ],
        'confidentiality:confidential' => [
            'description' => 'Confidential, restricted access',
            'color' => 'orange',
        ],
        'confidentiality:restricted' => [
            'description' => 'Highly restricted, need-to-know only',
            'color' => 'red',
        ],

        // ============================================================
        // DEPARTMENT: Organizational Units
        // ============================================================
        'department:engineering' => [
            'description' => 'Engineering department',
            'color' => 'blue',
        ],
        'department:product' => [
            'description' => 'Product department',
            'color' => 'purple',
        ],
        'department:design' => [
            'description' => 'Design department',
            'color' => 'pink',
        ],
        'department:sales' => [
            'description' => 'Sales department',
            'color' => 'green',
        ],
        'department:marketing' => [
            'description' => 'Marketing department',
            'color' => 'rose',
        ],
        'department:operations' => [
            'description' => 'Operations department',
            'color' => 'amber',
        ],
        'department:finance' => [
            'description' => 'Finance department',
            'color' => 'emerald',
        ],
        'department:hr' => [
            'description' => 'Human resources department',
            'color' => 'violet',
        ],
        'department:legal' => [
            'description' => 'Legal department',
            'color' => 'red',
        ],
        'department:executive' => [
            'description' => 'Executive leadership',
            'color' => 'slate',
        ],
        'department:customer-success' => [
            'description' => 'Customer success and support',
            'color' => 'cyan',
        ],
        'department:it' => [
            'description' => 'Information technology',
            'color' => 'indigo',
        ],

        // ============================================================
        // AUDIENCE: Target Audience
        // ============================================================
        'audience:technical' => [
            'description' => 'For technical/engineering audience',
            'color' => 'blue',
        ],
        'audience:non-technical' => [
            'description' => 'For non-technical audience',
            'color' => 'green',
        ],
        'audience:executive' => [
            'description' => 'For executive leadership',
            'color' => 'purple',
        ],
        'audience:manager' => [
            'description' => 'For management level',
            'color' => 'indigo',
        ],
        'audience:customer' => [
            'description' => 'For external customers',
            'color' => 'pink',
        ],
        'audience:partner' => [
            'description' => 'For business partners',
            'color' => 'amber',
        ],
        'audience:vendor' => [
            'description' => 'For vendors and suppliers',
            'color' => 'orange',
        ],
        'audience:public' => [
            'description' => 'For general public',
            'color' => 'slate',
        ],
        'audience:internal' => [
            'description' => 'For internal employees only',
            'color' => 'cyan',
        ],

        // ============================================================
        // SOURCE: Content Source/Origin
        // ============================================================
        'source:notion' => [
            'description' => 'Imported from Notion',
            'color' => 'slate',
        ],
        'source:confluence' => [
            'description' => 'Imported from Confluence',
            'color' => 'blue',
        ],
        'source:google-docs' => [
            'description' => 'Imported from Google Docs',
            'color' => 'green',
        ],
        'source:slack' => [
            'description' => 'Imported from Slack',
            'color' => 'purple',
        ],
        'source:github' => [
            'description' => 'Imported from GitHub',
            'color' => 'slate',
        ],
        'source:web' => [
            'description' => 'Imported from web URL',
            'color' => 'cyan',
        ],
        'source:file-upload' => [
            'description' => 'Uploaded file',
            'color' => 'indigo',
        ],
        'source:text-entry' => [
            'description' => 'Manual text entry',
            'color' => 'sky',
        ],
    ],
];
