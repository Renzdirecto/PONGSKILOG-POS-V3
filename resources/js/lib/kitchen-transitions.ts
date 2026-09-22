import type {
    KitchenBoardData,
    KitchenStatus,
    KitchenTicket,
    KitchenTransitionFlash,
} from '../types/kitchen';

export type KitchenTransitionResult = KitchenTransitionFlash & {
    version: number;
};
type Transition = { target: KitchenStatus; version: number; pending: boolean };
export type KitchenTransitions = Record<string, Transition>;

/** Short-lived overlays; server versions retire confirmed results. */
export class KitchenTransitionStore {
    private state: KitchenTransitions = {};
    private listeners = new Set<() => void>();

    snapshot = () => this.state;
    subscribe = (listener: () => void) => {
        this.listeners.add(listener);
        return () => {
            this.listeners.delete(listener);
        };
    };

    private publish(state: KitchenTransitions) {
        this.state = state;
        this.listeners.forEach((listener) => listener());
    }

    async run(
        ticket: KitchenTicket,
        target: KitchenStatus,
        request: () => Promise<KitchenTransitionResult>,
        onReady: () => void,
        onError: (error: unknown) => void,
        refresh: () => void,
    ): Promise<void> {
        if (this.state[ticket.id]?.pending) {
            return;
        }
        const previous = this.state[ticket.id];
        this.publish({
            ...this.state,
            [ticket.id]: { target, version: ticket.version, pending: true },
        });
        try {
            const result = await request();
            if (
                result.order_id !== ticket.id ||
                result.to !== target ||
                !Number.isInteger(result.version)
            ) {
                throw new Error(
                    'The kitchen response could not be confirmed. Please refresh.',
                );
            }
            this.publish({
                ...this.state,
                [ticket.id]: {
                    target: result.to,
                    version: result.version,
                    pending: false,
                },
            });
            if (target === 'ready' && result.changed) {
                onReady();
            }
        } catch (error) {
            const next = { ...this.state };
            if (previous) {
                next[ticket.id] = previous;
            } else {
                delete next[ticket.id];
            }
            this.publish(next);
            onError(error);
        } finally {
            refresh();
        }
    }

    reconcile(board: KitchenBoardData) {
        const next = { ...this.state };
        for (const [id, transition] of Object.entries(next)) {
            const ticket = board.tickets.find((ticket) => ticket.id === id);
            if (
                !transition.pending &&
                (!board.is_open ||
                    !ticket ||
                    ticket.version >= transition.version)
            ) {
                delete next[id];
            }
        }
        if (Object.keys(next).length !== Object.keys(this.state).length) {
            this.publish(next);
        }
    }
}

export function projectKitchenBoard(
    board: KitchenBoardData,
    transitions: KitchenTransitions,
): KitchenBoardData {
    if (!board.is_open) {
        return board;
    }
    const counts = { ...board.counts };
    const tickets = board.tickets.map((ticket) => {
        const transition = transitions[ticket.id];
        if (
            !transition ||
            (transition.pending
                ? ticket.version > transition.version
                : ticket.version >= transition.version)
        ) {
            return ticket;
        }
        counts[ticket.status] -= 1;
        counts[transition.target] += 1;
        counts.all +=
            Number(transition.target !== 'done') -
            Number(ticket.status !== 'done');
        return {
            ...ticket,
            status: transition.target,
            version: transition.version,
        };
    });
    return { ...board, tickets, counts };
}
