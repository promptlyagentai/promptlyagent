# Q2 Launch Planning Meeting - Meridian Hospitality Group

Tags: type:meeting-note client:meridian-hospitality-group project:unified-booking-platform meeting-date:2025-12-03

**Date:** December 3, 2025
**Time:** 2:00 PM - 3:30 PM EST
**Location:** Video Conference (Google Meet)

## Attendees

**Meridian Team:**
- Christina Hayes (Chief Digital Officer)
- Michael Chang (VP of Technology & Operations)
- Lauren Martinez (Director of Revenue Management)
- David Kim (Director of Guest Experience)

**Company Team:**
- Morgan Bennett (Account Lead)
- Sarah Chen (Project Manager)
- Alex Rodriguez (Technical Lead)
- Jordan Taylor (UX Lead)

## Meeting Objective

Review Q2 launch timeline and finalize property rollout sequence for the unified booking platform.

## Key Discussion Points

### 1. Launch Timeline Confirmation
- Target launch date: February 15, 2026 (start of spring booking season)
- Soft launch with 3 pilot properties: Oceanview Resort, Mountain Lodge, Urban Boutique
- Full rollout to all 28 properties by March 31, 2026
- **Decision:** Christina approved phased approach to minimize risk

### 2. Property Rollout Sequence
Lauren presented revenue impact analysis:
- **Phase 1 (Feb 15):** 3 pilot properties - highest technical readiness
- **Phase 2 (Feb 28):** 8 properties - high-volume booking sites
- **Phase 3 (Mar 15):** 12 properties - mid-tier properties
- **Phase 4 (Mar 31):** 5 properties - lower volume, complex integrations

**Action Item:** Sarah to create detailed rollout schedule with cutover windows

### 3. Opera PMS Integration Status
Michael raised concerns about API rate limits during peak booking times.

**Resolution:**
- Alex confirmed implementation of request queuing and caching layer
- Performance testing scheduled for Dec 15-20 with simulated peak load
- Fallback mechanism in place if Opera API becomes unavailable

**Action Item:** Alex to document fallback procedures and train support team

### 4. Direct Booking Incentives
David proposed special promotional rates for Q2 launch:
- 15% discount for direct bookings made through new platform
- Loyalty points bonus for early adopters
- Email campaign to existing guest database

**Discussion:** Team debated timing - launch incentives vs. post-launch stabilization

**Decision:** Run promotions starting March 1 (after pilot phase proves stability)

### 5. Staff Training & Change Management
- Property staff training sessions scheduled for January
- Video tutorials and documentation being finalized
- Dedicated support hotline for first 30 days post-launch

**Action Item:** Jordan to complete training materials by December 20

### 6. OTA Rate Parity Compliance
Lauren emphasized importance of maintaining rate parity during transition.

**Commitment:**
- Real-time rate synchronization across all channels
- Daily automated parity checks
- Manual review process for first 60 days

**Action Item:** Michael to coordinate with channel manager vendor (TravelClick)

## Risks & Mitigation

| Risk | Impact | Mitigation |
|------|--------|-----------|
| Opera API instability during peak hours | High | Caching layer, fallback UI, extended testing |
| Property staff resistance to new system | Medium | Comprehensive training, dedicated support, executive messaging |
| Peak season timing pressure | High | Phased rollout, pilot validation, rollback plans |
| Payment processing integration delays | Medium | Stripe integration complete; backup payment gateway configured |

## Action Items

| Action | Owner | Due Date | Status |
|--------|-------|----------|--------|
| Create detailed property rollout schedule | Sarah Chen | Dec 10 | In Progress |
| Complete performance testing with peak load simulation | Alex Rodriguez | Dec 20 | Planned |
| Document Opera API fallback procedures | Alex Rodriguez | Dec 15 | Planned |
| Finalize training materials and videos | Jordan Taylor | Dec 20 | In Progress |
| Coordinate with TravelClick on rate parity monitoring | Michael Chang | Dec 12 | Planned |
| Draft promotional campaign for post-launch incentives | David Kim | Jan 5 | Planned |

## Decisions Made

1. ✅ Approved February 15, 2026 soft launch date
2. ✅ Confirmed 4-phase property rollout approach
3. ✅ Launch promotions to begin March 1 (post-pilot validation)
4. ✅ Extended performance testing period (Dec 15-20)
5. ✅ Dedicated support hotline for 30 days post-launch

## Next Steps

- **Next Meeting:** December 17, 2025 - Technical Readiness Review
- **Focus:** Performance testing results, training materials review
- **Preparation:** Alex to prepare load testing report; Jordan to demo training materials

## Notes

Christina expressed strong confidence in the team and timeline. She emphasized the strategic importance of this launch for reducing OTA dependency and improving direct booking margins. Michael requested weekly technical syncs through launch to ensure proactive issue resolution.

Team morale is excellent. Everyone aligned on timeline and deliverables.

---

**Meeting Notes By:** Morgan Bennett
**Distribution:** All attendees, project team, executive stakeholders
