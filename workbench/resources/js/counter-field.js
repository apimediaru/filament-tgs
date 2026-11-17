export default function counterField({ wire, path }) {
    return {
        get value() {
            return wire.$get(path)
        },
        increment() {
            wire.$set(path, (this.value ?? 0) + 1, false)
        },
    }
}
