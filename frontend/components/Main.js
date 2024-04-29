import { useState } from "react";
import NavBar     from "./NavBar";
import UserLayout from "./UserLayout";

export default function Main({ appName }) {
    const [ navbarHeight, setNavbarHeight ] = useState(0);

    return (
        <>
            <NavBar     appName={appName} heightReporter={setNavbarHeight}  />
            <UserLayout navbarHeight={navbarHeight}                         />
        </>
    );
}