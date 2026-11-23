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

/** The part of the Places library that sends the request (AutocompleteSuggestion). */
export type SuggestionSender<T> = {
    AutocompleteSuggestion: {
        fetchAutocompleteSuggestions: (
            request: SuggestionRequest,
        ) => Promise<{ suggestions: T[] }>;
    };
};

/** Sends the suggestion request (every address field and the Quick booking address check go through here). */
export function fetchSuggestions<T>(
    places: SuggestionSender<T>,
    input: string,
    country: string,
    area: ServiceArea | null | undefined,
    sessionToken?: unknown,
): Promise<{ suggestions: T[] }> {
    return places.AutocompleteSuggestion.fetchAutocompleteSuggestions(
        suggestionRequest(input, country, area, sessionToken),
    );
}
