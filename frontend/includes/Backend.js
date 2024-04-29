import axios from "axios";

axios.defaults.withCredentials = true;
axios.defaults.headers         = { Accept: 'application/json' };

const BACKEND_BASE_URL = '/backend';

export default ({
    get: (url, data) => (
        axios.get(`${BACKEND_BASE_URL}${url}${
            typeof data != 'undefined'
                ? '?' + (new URLSearchParams(data).toString())
                : ''
        }`)
    ),
    post: (url, data) => (
        axios.postForm(
            `${BACKEND_BASE_URL}${url}`, // url
            data                         // data
        )
    )
});