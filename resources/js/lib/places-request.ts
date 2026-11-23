import type { ServiceArea } from '@/types';

/** The request of Places API (New) AutocompleteSuggestion.fetchAutocompleteSuggestions, as used here. */
export type SuggestionRequest = {
    input: string;
    includedRegionCodes?: string[];
    /** A hard limit: nothing outside the box is suggested (unlike locationBias). */
    locationRestriction?: ServiceArea;
    sessionToken?: unknown;
    language?: string;
};

/**
 * The address suggestion request: limited to the country and, when the company has a service area
 * (Company → Service area), strictly to that box (locationRestriction, never locationBias).
 */
export function suggestionRequest(
    input: string,
    country: string,
    area: ServiceArea | null | undefined,
    sessionToken?: unknown,
): SuggestionRequest {
    return {
        input,
        includedRegionCodes: [country.toLowerCase()],
        ...(area
            ? {
                  locationRestriction: {
                      south: area.south,
                      west: area.west,
                      north: area.north,
                      east: area.east,
                  },
              }
            : {}),
        ...(sessionToken ? { sessionToken } : {}),
    };
}
