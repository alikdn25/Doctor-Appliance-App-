import assert from 'node:assert/strict';
import { test } from 'node:test';
import { suggestionRequest } from '../../resources/js/lib/places-request.ts';

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
