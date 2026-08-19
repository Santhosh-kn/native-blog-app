import Foundation

enum BiometricFunctions {

    class IsAvailable: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            // TODO: Implement when building iOS support
            return BridgeResponse.success(data: ["available": false])
        }
    }

    class Authenticate: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            // TODO: Implement when building iOS support
            return BridgeResponse.success(data: [:])
        }
    }
}