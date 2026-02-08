# Epic Integration Kickoff - HealthFirst Medical Group

Tags: type:meeting-note client:healthfirst-medical-group project:patient-portal meeting-date:2025-09-15

**Date:** September 15, 2025
**Time:** 1:00 PM - 3:00 PM EST
**Location:** HealthFirst HQ, Boston MA (Hybrid)

## Attendees

**HealthFirst Team:**
- Dr. Amanda Foster (CMIO)
- Michael Torres (VP of Information Technology)
- James Liu (Director of Information Security)
- David Kim (Epic Systems Administrator)
- Patricia Martinez (Director of Care Coordination)

**Company Team:**
- Marcus Chen (Account Lead)
- Jennifer Wong (Technical Architect)
- Robert Martinez (Epic Integration Specialist)
- Lisa Thompson (Project Manager)

**Epic Representative:**
- Sarah O'Connor (Integration Consultant) - via video

## Meeting Objective

Establish technical approach and timelines for Epic FHIR API integration with patient portal.

## Key Discussion Points

### 1. Epic MyChart vs. Custom Portal Strategy
Dr. Foster opened with strategic context on why custom portal vs. MyChart alone:
- Need for specialized workflows not available in MyChart
- Branding and patient experience control
- Integration with non-Epic systems (lab partners, imaging centers)
- Future telemedicine platform requirements

**Decision:** Proceed with custom portal using Epic FHIR APIs as primary data source.

### 2. Epic API Access & Authentication
David Kim reviewed Epic environment details:
- HealthFirst on Epic version May 2025
- FHIR R4 APIs available
- MyChart OAuth already configured
- SMART on FHIR capability enabled

**Technical Decisions:**
- Use SMART on FHIR for patient authentication (OAuth 2.0)
- Backend Services authorization for system-to-system calls
- Patient-facing app registered in Epic App Orchard

**Action Item:** David to provide Epic sandbox credentials by Sept 20.

### 3. FHIR Resources Scope for Phase 1

Team aligned on initial FHIR resources to integrate:

**Patient Demographics & Context:**
- Patient resource (name, contact, demographics)
- Coverage resource (insurance information)

**Clinical Data:**
- Condition resource (problem list)
- MedicationRequest (current medications)
- AllergyIntolerance (allergies)
- Immunization (vaccination history)
- Observation (vital signs, lab results)
- DiagnosticReport (lab reports, imaging results)

**Scheduling:**
- Appointment resource (view, book, cancel)
- Slot resource (available appointment times)
- Schedule resource (provider schedules)

**Communication:**
- Communication resource (secure messaging)

**Phase 2 Resources (Future):**
- DocumentReference (clinical documents)
- CarePlan (care management)
- Procedure (surgical history)

### 4. Real-Time vs. Cached Data Strategy
Jennifer presented architectural options for data freshness.

**Discussion:**
- Balancing API rate limits vs. data freshness
- Epic API rate limit: 500 requests per hour per client
- Peak portal usage: estimated 200 concurrent users

**Decisions:**
- **Real-time:** Appointments, messaging, medication list
- **Cached (15 min):** Lab results, vital signs, problem list
- **Cached (24 hours):** Immunization history, allergies
- Client-side caching for patient demographics during session

**Action Item:** Robert to document caching strategy and Epic API rate limit management plan.

### 5. Security & Compliance Requirements
James Liu presented security requirements:

**Must-Have Security Controls:**
- All API calls over TLS 1.2+
- OAuth tokens encrypted at rest
- Token rotation every 60 days
- Audit logging of all Epic API calls
- PHI access logging with patient identifier
- Failed authentication monitoring & alerting

**HIPAA Compliance:**
- BAA already in place with Company
- Epic integration covered under existing HealthFirst-Epic BAA
- Penetration testing required before production launch

**Action Item:** Jennifer to provide security architecture document by Sept 30.

### 6. Epic Sandbox Environment Access
Sarah O'Connor (Epic) explained sandbox access process:
- Sandbox environment available within 5 business days
- Includes synthetic patient data (100 test patients)
- Mirrors production FHIR API capabilities
- Rate limits relaxed for testing

**Action Item:** David to complete Epic sandbox access request form (sent during meeting).

### 7. Integration Timeline

**Phase 1: Foundation (Weeks 1-4)**
- Epic sandbox access & authentication setup
- FHIR API client development
- Patient demographic & context integration

**Phase 2: Clinical Data (Weeks 5-8)**
- Problem list, medications, allergies integration
- Lab results & vital signs display
- Immunization history

**Phase 3: Scheduling (Weeks 9-11)**
- Appointment viewing
- Appointment booking & cancellation
- Provider schedule integration

**Phase 4: Messaging (Weeks 12-13)**
- Secure messaging with providers
- Message notifications

**Phase 5: Testing & Launch (Weeks 14-16)**
- User acceptance testing
- Security penetration testing
- Production deployment

**Target Go-Live:** Mid-January 2026

## Technical Considerations & Risks

| Consideration | Impact | Mitigation |
|---------------|--------|-----------|
| Epic API rate limits | Medium | Intelligent caching, request batching, rate limit monitoring |
| FHIR data model complexity | Medium | Epic integration specialist on team, thorough testing |
| Appointment booking workflow customization | High | Early validation with Epic, fallback to MyChart for edge cases |
| Epic version upgrades during project | Low | Monitor Epic release schedule, sandbox testing for changes |

## Action Items

| Action | Owner | Due Date | Status |
|--------|-------|----------|--------|
| Provide Epic sandbox credentials | David Kim | Sept 20 | Planned |
| Complete Epic sandbox access request | David Kim | Sept 16 | In Progress |
| Document caching strategy & rate limit mgmt | Robert Martinez | Sept 25 | Planned |
| Provide security architecture document | Jennifer Wong | Sept 30 | Planned |
| Develop FHIR API authentication module | Robert Martinez | Oct 5 | Planned |
| Schedule follow-up technical deep dive | Lisa Thompson | Sept 22 | Planned |

## Decisions Made

1. ✅ Proceed with custom portal using Epic FHIR APIs
2. ✅ Use SMART on FHIR for patient authentication
3. ✅ Approved Phase 1 FHIR resources scope
4. ✅ Real-time vs. cached data strategy defined
5. ✅ Target go-live: Mid-January 2026

## Next Steps

- **Next Meeting:** September 29, 2025 - Sandbox Environment Review
- **Focus:** Review sandbox setup, demo authentication flow, validate FHIR queries
- **Preparation:** Robert to prepare demo of SMART on FHIR auth; David to configure sandbox

## Notes

Dr. Foster emphasized the strategic importance of this integration for patient engagement goals. She's particularly excited about appointment booking workflow reducing call center volume.

James Liu requested detailed data flow diagrams before security architecture review. Jennifer committed to providing by Sept 25.

Sarah O'Connor (Epic) was extremely helpful and offered to join future technical sessions if needed. Team appreciated Epic's collaboration.

David Kim noted that HealthFirst is planning Epic upgrade to August 2026 version in Q3 2026. Team will monitor for FHIR API changes.

---

**Meeting Notes By:** Marcus Chen
**Distribution:** All attendees, project team, security review committee
