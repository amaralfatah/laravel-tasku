import { Link } from '@inertiajs/react';
import { Fragment } from 'react';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function Breadcrumbs({
    breadcrumbs,
}: {
    breadcrumbs: BreadcrumbItemType[];
}) {
    return (
        <>
            {breadcrumbs.length > 0 && (
                <Breadcrumb className="min-w-0">
                    {/*
                     * The trail sits inside a fixed-height bar, so it must not
                     * wrap: on a phone it keeps the current page only — the
                     * ancestors are one back-swipe away and were what pushed a
                     * long project name onto a second line.
                     */}
                    <BreadcrumbList className="flex-nowrap">
                        {breadcrumbs.map((item, index) => {
                            const isLast = index === breadcrumbs.length - 1;

                            return (
                                <Fragment key={index}>
                                    <BreadcrumbItem
                                        className={
                                            isLast
                                                ? 'min-w-0'
                                                : 'hidden sm:inline-flex'
                                        }
                                    >
                                        {isLast ? (
                                            <BreadcrumbPage className="truncate">
                                                {item.title}
                                            </BreadcrumbPage>
                                        ) : !item.href ? (
                                            // A crumb that leads nowhere: it
                                            // names a place, so it must not
                                            // claim to be the current page
                                            // either.
                                            <span className="whitespace-nowrap">
                                                {item.title}
                                            </span>
                                        ) : (
                                            <BreadcrumbLink asChild>
                                                <Link
                                                    href={item.href}
                                                    className="whitespace-nowrap"
                                                >
                                                    {item.title}
                                                </Link>
                                            </BreadcrumbLink>
                                        )}
                                    </BreadcrumbItem>
                                    {!isLast && (
                                        <BreadcrumbSeparator className="hidden sm:block" />
                                    )}
                                </Fragment>
                            );
                        })}
                    </BreadcrumbList>
                </Breadcrumb>
            )}
        </>
    );
}
