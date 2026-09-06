// Fixture data for the mock AIVIS Public API.
//
// Shaped to exercise the cases the connector actually has to survive, not the
// happy path: two businesses sharing one domain (the duplicate-onboarding
// case), one URL living in two chains (the winner rule), a URL that is present
// but ungenerated (the 404 that must NOT deactivate), and a retraction the
// scenario engine can advance through its three stages.

export const SITE = 'https://example.com';

export const businesses = [
  { id: 'biz_live',  name: 'Example GmbH',            baseUrl: 'https://example.com',
    industry: { key: 'general', name: 'General' }, chainCount: 3, createdAt: '2026-03-01T10:00:00.000Z' },
  // Same domain, different business — a rebuild. This is why businessId
  // equality is checked on every artifact (SPECIFICATION §07).
  { id: 'biz_rebuild', name: 'Example GmbH (rebuild)', baseUrl: 'https://example.com',
    industry: { key: 'general', name: 'General' }, chainCount: 1, createdAt: '2026-08-20T09:00:00.000Z' },
  { id: 'biz_other', name: 'Anderer Kunde AG',        baseUrl: 'https://andere-domain.de',
    industry: { key: 'general', name: 'General' }, chainCount: 1, createdAt: '2026-04-11T08:00:00.000Z' },
];

export const chains = {
  biz_live:    [
    { id: 'chain_core', name: 'Structural core', description: null, state: 'ready',
      currentStep: 9, knowledgeGraphReady: true, graphScore: 0.82, urlCount: 4, createdAt: '2026-03-02T10:00:00.000Z' },
    { id: 'chain_edit', name: 'Editorial',       description: 'Blog', state: 're_ingesting',
      currentStep: 9, knowledgeGraphReady: true, graphScore: 0.71, urlCount: 2, createdAt: '2026-05-02T10:00:00.000Z' },
    // One chain per language (SPECIFICATION §07a): the English site lives in
    // its own chain, under /en/ on the same domain.
    { id: 'chain_en',   name: 'English site',    description: null, state: 'ready',
      currentStep: 9, knowledgeGraphReady: true, graphScore: 0.77, urlCount: 2, createdAt: '2026-06-01T10:00:00.000Z' },
  ],
  biz_rebuild: [
    { id: 'chain_rebuild', name: 'Rebuild', description: null, state: 'building',
      currentStep: 4, knowledgeGraphReady: false, graphScore: null, urlCount: 1, createdAt: '2026-08-21T10:00:00.000Z' },
  ],
  biz_other:   [
    { id: 'chain_other', name: 'Other', description: null, state: 'ready',
      currentStep: 9, knowledgeGraphReady: true, graphScore: 0.6, urlCount: 1, createdAt: '2026-04-12T10:00:00.000Z' },
  ],
};

// url rows. `artifact: null` = present in inventory but never generated.
export const urls = [
  { id: 'u_home',    businessId: 'biz_live', chainId: 'chain_core', url: 'https://example.com/',
    languageCode: 'de', layer: 'structural_core', captureStatus: 'processed',
    artifact: { generatedAt: '2026-09-01T10:00:00.000Z', staleAt: null, jsonLd: { '@context': 'https://schema.org', '@type': 'Organization', name: 'Example GmbH' } } },

  { id: 'u_services', businessId: 'biz_live', chainId: 'chain_core', url: 'https://example.com/services/',
    languageCode: 'de', layer: 'structural_core', captureStatus: 'processed',
    artifact: { generatedAt: '2026-09-02T10:00:00.000Z', staleAt: '2026-09-03T10:00:00.000Z', jsonLd: { '@context': 'https://schema.org', '@type': 'Service', name: 'Services' } } },

  // Same URL, second chain, fresher + non-stale — must win the tie-break.
  { id: 'u_services_edit', businessId: 'biz_live', chainId: 'chain_edit', url: 'https://example.com/services/',
    languageCode: 'de', layer: 'editorial', captureStatus: 'processed',
    artifact: { generatedAt: '2026-09-04T10:00:00.000Z', staleAt: null, jsonLd: { '@context': 'https://schema.org', '@type': 'Service', name: 'Services (editorial)' } } },

  // In inventory, no artifact: the 404 that must never deactivate anything.
  { id: 'u_pending', businessId: 'biz_live', chainId: 'chain_core', url: 'https://example.com/kontakt/',
    languageCode: 'de', layer: 'structural_core', captureStatus: 'processing', artifact: null },

  // The retraction subject. Scenario stages mutate this row.
  { id: 'u_retract', businessId: 'biz_live', chainId: 'chain_core', url: 'https://example.com/altes-angebot/',
    languageCode: 'de', layer: 'editorial', captureStatus: 'processed',
    artifact: { generatedAt: '2026-08-15T10:00:00.000Z', staleAt: null, jsonLd: { '@context': 'https://schema.org', '@type': 'Offer', name: 'Altes Angebot' } } },

  // The English chain: same domain, /en/ prefix, languageCode en.
  { id: 'u_en_home', businessId: 'biz_live', chainId: 'chain_en', url: 'https://example.com/en/',
    languageCode: 'en', layer: 'structural_core', captureStatus: 'processed',
    artifact: { generatedAt: '2026-09-03T10:00:00.000Z', staleAt: null, jsonLd: { '@context': 'https://schema.org', '@type': 'Organization', name: 'Example Ltd' } } },
  { id: 'u_en_services', businessId: 'biz_live', chainId: 'chain_en', url: 'https://example.com/en/services/',
    languageCode: 'en', layer: 'structural_core', captureStatus: 'processed',
    artifact: { generatedAt: '2026-09-03T10:00:00.000Z', staleAt: null, jsonLd: { '@context': 'https://schema.org', '@type': 'Service', name: 'Services (en)' } } },

  // Cross-business collision: same path, different business. The client guard
  // must reject this if it is ever returned for the selected business.
  { id: 'u_rebuild_home', businessId: 'biz_rebuild', chainId: 'chain_rebuild', url: 'https://example.com/rebuild-only/',
    languageCode: 'de', layer: 'structural_core', captureStatus: 'processed',
    artifact: { generatedAt: '2026-09-05T10:00:00.000Z', staleAt: null, jsonLd: { '@context': 'https://schema.org', '@type': 'WebPage', name: 'Rebuild' } } },

  { id: 'u_other', businessId: 'biz_other', chainId: 'chain_other', url: 'https://andere-domain.de/',
    languageCode: 'de', layer: 'structural_core', captureStatus: 'processed',
    artifact: { generatedAt: '2026-09-01T10:00:00.000Z', staleAt: null, jsonLd: { '@context': 'https://schema.org', '@type': 'Organization', name: 'Anderer' } } },
];

export const VALID_TOKEN = 'aivis_' + 'M0ckT0kenForContractTestsOnly_notARealCredential1';
