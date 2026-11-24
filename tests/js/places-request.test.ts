import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    fetchSuggestions,
    suggestionRequest,
} from '../../resources/js/lib/places-request.ts';
import type { SuggestionRequest } from '../../resources/js/lib/places-request.ts';

const lowerMainland = { south: 49.0, north: 49.5, west: -123.35, east: -121.3 };

void test('suggestions are limited strictly to the company box and to Canada', () => {
    const token = { session: 1 };
    const request = suggestionRequest('123 Main', 'CA', lowerMainland, token);

    assert.deepEqual(request, {
        input: '123 Main',
        includedRegionCodes: ['ca'],
        locationRestriction: {
            south: 49.0,
            west: -123.35,
            north: 49.5,
            east: -121.3,
        },
        sessionToken: token,
    });
    assert.equal('locationBias' in request, false);
});

void test('without a service area only the country limits suggestions', () => {
    assert.deepEqual(suggestionRequest('Main St', 'US', null), {
        input: 'Main St',
        includedRegionCodes: ['us'],
    });
});

void test('the suggestion request sent to Google carries the company box and region ca', async () => {
    const sent: SuggestionRequest[] = [];
    const places = {
        AutocompleteSuggestion: {
            fetchAutocompleteSuggestions: async (
                request: SuggestionRequest,
            ) => {
                sent.push(request);

                return { suggestions: ['4500 Kingsway'] };
            },
        },
    };

    const answer = await fetchSuggestions(
        places,
        '4500 King',
        'CA',
        lowerMainland,
    );

    assert.equal(sent.length, 1);
    assert.deepEqual(sent[0].includedRegionCodes, ['ca']);
    assert.deepEqual(sent[0].locationRestriction, {
        south: 49.0,
        west: -123.35,
        north: 49.5,
        east: -121.3,
    });
    assert.equal('locationBias' in sent[0], false);
    assert.deepEqual(answer.suggestions, ['4500 Kingsway']);
});
