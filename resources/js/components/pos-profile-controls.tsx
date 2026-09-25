import { Link } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { logout } from '@/routes';
import type { Auth } from '@/types';

export function PosProfileControls({ auth }: { auth: Auth }) {
    const initials = auth.user?.name
        .split(' ')
        .filter(Boolean)
        .map((part) => part[0])
        .slice(0, 2)
        .join('');
    const role =
        auth.roleLabel ??
        auth.roles
            .map((value) =>
                value
                    .split('_')
                    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
                    .join(' '),
            )
            .join(' / ');
    const avatar = (
        <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-neutral-950 text-xs font-bold text-white">
            {initials}
        </span>
    );
    const popover =
        'pos-surface w-[320px] max-w-[calc(100vw-24px)] rounded-[14px] border-neutral-200 bg-white p-0 text-neutral-950 shadow-xl';
    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        aria-label="Cashier profile"
                        className="flex h-11 shrink-0 items-center gap-[9px] rounded-xl border border-neutral-200 bg-white px-1.5 md:pr-3"
                    >
                        {avatar}
                        <span className="hidden max-w-36 truncate text-[12.5px] font-semibold md:block">
                            {auth.user?.name}
                        </span>
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="end"
                    sideOffset={8}
                    className={popover}
                >
                    <div className="flex items-center gap-3 border-b border-neutral-200 p-4">
                        <span className="flex size-11 shrink-0 items-center justify-center rounded-full bg-neutral-950 text-[15px] font-bold text-white">
                            {initials}
                        </span>
                        <div className="min-w-0">
                            <p className="text-[13px] font-semibold wrap-anywhere">
                                {auth.user?.name}
                            </p>
                            <p className="text-[11px] text-neutral-500">
                                {role}
                            </p>
                        </div>
                    </div>
                    <div className="p-2">
                        <DropdownMenuItem asChild>
                            <Link
                                href={logout()}
                                as="button"
                                className="flex h-11 w-full items-center gap-2 rounded-lg px-3 text-[13px] font-semibold text-red-700"
                            >
                                <LogOut className="size-4" />
                                Log out
                            </Link>
                        </DropdownMenuItem>
                    </div>
                </DropdownMenuContent>
            </DropdownMenu>
        </>
    );
}
