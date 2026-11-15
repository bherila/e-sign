const assert = require('node:assert/strict');
const { createRequire } = require('node:module');
const { dirname, join } = require('node:path');
const { test } = require('node:test');

// Resolve the copy Jest actually consumes, including pnpm's patch hash. A new
// direct dependency could conceal an unpatched copy used by the real consumer.
let jestConfigRequire = require;
for (const name of ['jest', 'jest-cli', 'jest-config']) {
    jestConfigRequire = createRequire(jestConfigRequire.resolve(`${name}/package.json`));
}
const { mergeConfig } = jestConfigRequire('jest-config');
const mergePath = jestConfigRequire.resolve('deepmerge');
const payload = () => JSON.parse('{"__proto__":{"isAdmin":true}}');

for (const [entry, merge] of [
    ['CommonJS', jestConfigRequire('deepmerge')],
    ['UMD', require(join(dirname(mergePath), 'umd.js'))],
]) {
    void test(`deepmerge ${entry} prevents returned-prototype poisoning for reported target shapes`, () => {
        const results = [
            merge(Object.create(null), payload()),
            ...[null, undefined, 0, false, ''].map((value) => merge({ session: value }, { session: payload() }).session),
            merge(payload(), {}),
            merge(payload(), payload()),
            merge({ items: [payload()] }, { items: [] }).items[0],
            merge.all([Object.create(null), payload()]),
        ];
        for (const result of results) {
            assert.equal(Object.getPrototypeOf(result), Object.prototype);
            assert.equal(result.isAdmin, undefined);
            assert.equal(Object.hasOwn(result, '__proto__'), false);
        }
        assert.equal({}.isAdmin, undefined);
    });

    void test(`deepmerge ${entry} preserves normal values, symbols, arrays and input immutability`, () => {
        const symbol = Symbol('metadata');
        const pattern = /\.tsx?$/;
        const defaults = { nested: { first: true }, flags: ['first'], pattern, [symbol]: { first: true } };
        const overrides = { nested: { second: true }, flags: ['second'], [symbol]: { second: true } };
        assert.deepEqual(merge(defaults, overrides), {
            nested: { first: true, second: true }, flags: ['first', 'second'], pattern,
            [symbol]: { first: true, second: true },
        });
        assert.deepEqual(defaults.flags, ['first']);
        assert.deepEqual(overrides.flags, ['second']);
        assert.deepEqual(merge({ flags: ['first'] }, { flags: ['second'] }, {
            arrayMerge: (_target, source) => source,
        }), { flags: ['second'] });
    });
}

void test('the actual Jest configuration merger keeps supported configuration and blocks nested poisoning', () => {
    const pattern = /\.tsx?$/;
    const defaults = { globals: { nested: { first: true }, flags: ['first'] }, transform: { pattern } };
    const overrides = { globals: { nested: { second: true }, flags: ['second'] } };
    assert.deepEqual(mergeConfig(defaults, overrides), {
        globals: { nested: { first: true, second: true }, flags: ['first', 'second'] },
        transform: { pattern },
    });
    const result = mergeConfig({ globals: { session: null } }, { globals: { session: payload() } }).globals.session;
    assert.equal(Object.getPrototypeOf(result), Object.prototype);
    assert.equal(result.isAdmin, undefined);
    assert.deepEqual(defaults.globals.flags, ['first']);
    assert.deepEqual(overrides.globals.flags, ['second']);
});
